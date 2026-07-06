<?php

namespace Tests\Feature;

use App\Events\AiDecisionRecorded;
use App\Jobs\ProcessIncomingWhatsappMessage;
use App\Livewire\Contatos;
use App\Livewire\ContactTags;
use App\Livewire\Kanban;
use App\Livewire\Regras;
use App\Models\Account;
use App\Models\AutoReplyRule;
use App\Models\AutoReplySetting;
use App\Models\Board;
use App\Models\BoardRule;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\Tag;
use App\Whatsapp\AutoReply\RuleWriter;
use App\Whatsapp\Secrets\SecretVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tags T-1 — segmentacao. HTTP mockado (nunca envio real). Provas: CRUD/pivo
 * idempotente com origem, acoes de tag no motor (CUMULATIVAS; move segue
 * first-match), condicao por intent, escopo por tag em regras/fluxos avaliado na
 * hora, especificidade contato > tag > global, conflito detectado, guarda S5
 * (segredo JAMAIS por tag) no writer e na UI, filtro do Kanban.
 */
class TagsTest extends TestCase
{
    use RefreshDatabase;

    private const JID = '5541999990000@s.whatsapp.net';
    private Account $account;
    private Channel $channel;
    private Contact $contact;
    private Board $board;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 7, 2, 10, 0, 0, 'America/Sao_Paulo'));
        Http::fake(['*' => Http::response(['key' => ['id' => 'PMID']], 201)]);

        $this->account = Account::create(['name' => 'T']);
        $this->channel = Channel::create(['account_id' => $this->account->id, 'instance' => 'fabio-pessoal', 'status' => 'connected']);
        AutoReplySetting::create([
            'account_id' => $this->account->id, 'enabled' => true, 'reply_policy' => 'all',
            'window_start' => '08:00:00', 'window_end' => '20:00:00',
            'min_interval_seconds' => 0, 'per_minute_cap' => 100, 'per_day_cap' => 100,
            'contact_rate_seconds' => 0, 'contact_rate_enabled' => false,
            'delay_min_seconds' => 0, 'delay_max_seconds' => 0,
        ]);
        $this->contact = Contact::create(['account_id' => $this->account->id, 'remote_jid' => self::JID, 'auto_reply_mode' => 'on', 'push_name' => 'Cliente']);
        $this->board = Board::withoutAccountScope()->where('account_id', $this->account->id)->where('is_default', true)->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tag(string $name, string $color = 'zinc'): Tag
    {
        return Tag::create(['account_id' => $this->account->id, 'name' => $name, 'color' => $color]);
    }

    private function regra(string $gatilho, string $resposta, string $scope = 'global', array $extra = []): AutoReplyRule
    {
        $rule = AutoReplyRule::create(array_merge([
            'account_id' => $this->account->id, 'match_type' => 'contains', 'match_value' => $gatilho,
            'response_text' => $resposta, 'enabled' => true, 'scope' => $scope,
        ], $extra));
        $rule->triggers()->create(['match_type' => 'contains', 'match_value' => $gatilho]);
        $rule->responses()->create(['response_text' => $resposta]);

        return $rule->fresh();
    }

    private function receber(string $texto, string $id = 'W1'): void
    {
        (new ProcessIncomingWhatsappMessage([
            'event' => 'messages.upsert', 'instance' => 'fabio-pessoal',
            'data' => [
                'key' => ['id' => $id, 'fromMe' => false, 'remoteJid' => self::JID],
                'pushName' => 'Cliente', 'messageType' => 'conversation',
                'message' => ['conversation' => $texto], 'messageTimestamp' => 1782699162,
            ],
        ]))->handle(
            app(\App\Contracts\WhatsappGateway::class),
            app(\App\Whatsapp\AutoReply\RuleMatcher::class),
            app(\App\Whatsapp\AutoReply\AntiBanGuard::class),
        );
    }

    // ---- CRUD / pivo ------------------------------------------------------------

    public function test_nome_de_tag_unico_por_conta(): void
    {
        $this->tag('vip');

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        Tag::create(['account_id' => $this->account->id, 'name' => 'vip']);
    }

    public function test_pivo_idempotente_reaplicar_e_noop(): void
    {
        $tag = $this->tag('vip');

        Livewire::test(ContactTags::class, ['contactId' => $this->contact->id])
            ->set('tagInput', 'vip')->call('addTag')
            ->set('tagInput', 'vip')->call('addTag'); // re-aplica -> no-op

        $this->assertSame(1, $this->contact->tags()->count());
        $this->assertSame('manual', $this->contact->tags()->first()->pivot->origin);
    }

    public function test_adicionar_cria_na_hora_e_remover_funciona_ate_pra_automatica(): void
    {
        // Criar na hora pelo input.
        Livewire::test(ContactTags::class, ['contactId' => $this->contact->id])
            ->set('tagInput', 'novo-cliente')->call('addTag');
        $this->assertDatabaseHas('tags', ['name' => 'novo-cliente', 'account_id' => $this->account->id]);

        // Tag de origem AUTOMATICA pode ser removida pelo humano.
        $auto = $this->tag('auto-tag');
        $this->contact->tags()->attach($auto->id, ['origin' => 'board_rule', 'origin_ref' => '99']);

        Livewire::test(ContactTags::class, ['contactId' => $this->contact->id])
            ->call('removeTag', $auto->id);
        $this->assertDatabaseMissing('contact_tag', ['contact_id' => $this->contact->id, 'tag_id' => $auto->id]);
    }

    // ---- motor: acoes de tag -------------------------------------------------------

    public function test_acoes_de_tag_sao_cumulativas_e_move_segue_first_match(): void
    {
        $t1 = $this->tag('lead');
        $t2 = $this->tag('ativo');
        $aguardando = $this->board->columns()->where('slug', 'aguardando')->first();

        // TRES regras pro mesmo evento: duas de TAG (cumulativas) + uma de MOVE
        // custom antes das default (first-match: so ela move).
        BoardRule::create(['account_id' => $this->account->id, 'board_id' => $this->board->id, 'event_type' => 'mensagem_recebida', 'conditions' => null, 'action_type' => 'add_tag', 'tag_id' => $t1->id, 'active' => true, 'position' => -3]);
        BoardRule::create(['account_id' => $this->account->id, 'board_id' => $this->board->id, 'event_type' => 'mensagem_recebida', 'conditions' => null, 'action_type' => 'add_tag', 'tag_id' => $t2->id, 'active' => true, 'position' => -2]);
        BoardRule::create(['account_id' => $this->account->id, 'board_id' => $this->board->id, 'event_type' => 'mensagem_recebida', 'conditions' => ['card' => 'absent'], 'action_type' => 'move_column', 'to_column_id' => $aguardando->id, 'active' => true, 'position' => -1]);

        $this->receber('oi');

        // AMBAS as tags aplicadas (cumulativo), com origem board_rule.
        $tags = $this->contact->tags()->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['lead', 'ativo'], $tags);
        // O move foi da regra custom (first-match) — a default "Novo" nao rodou.
        $card = \App\Models\Card::withoutAccountScope()->where('board_id', $this->board->id)->first();
        $this->assertSame((int) $aguardando->id, (int) $card->column_id);
        $this->assertSame(1, \App\Models\CardTransition::where('card_id', $card->id)->count());
    }

    public function test_remove_tag_no_motor_e_reentrega_idempotente(): void
    {
        $tag = $this->tag('aguardando-resposta');
        $this->contact->tags()->attach($tag->id, ['origin' => 'manual']);
        BoardRule::create(['account_id' => $this->account->id, 'board_id' => $this->board->id, 'event_type' => 'mensagem_recebida', 'conditions' => null, 'action_type' => 'remove_tag', 'tag_id' => $tag->id, 'active' => true, 'position' => -1]);

        $this->receber('oi');
        $this->assertSame(0, $this->contact->tags()->count());

        // Re-entrega do mesmo evento: nada muda (transicao unica; pivo ja ausente).
        $im = \App\Models\IncomingMessage::withoutAccountScope()->first();
        event(new \App\Events\IncomingMessageStored((int) $this->account->id, (int) $im->id, (int) $this->contact->id, self::JID));
        $this->assertSame(0, $this->contact->tags()->count());
        // Fatia 11: alem da criacao pela regra (novo), a mensagem sem resposta gera
        // a transicao sem_resposta -> aguardando. A re-entrega nao duplica nenhuma.
        $this->assertSame(1, \App\Models\CardTransition::where('event_type', 'mensagem_recebida')->count());
        $this->assertSame(1, \App\Models\CardTransition::where('event_type', 'sem_resposta')->count());
        $this->assertSame(2, \App\Models\CardTransition::count());
    }

    public function test_condicao_por_intent_aplica_a_tag_certa(): void
    {
        $tag = $this->tag('interessado-pagamento');
        BoardRule::create(['account_id' => $this->account->id, 'board_id' => $this->board->id, 'event_type' => 'ia_decisao', 'conditions' => ['intent' => 'pedir_pix'], 'action_type' => 'add_tag', 'tag_id' => $tag->id, 'active' => true, 'position' => 0]);

        // Intent diferente / acao nao-respondeu: NAO aplica.
        event(new AiDecisionRecorded((int) $this->account->id, 1, self::JID, 'respondeu', 'outra_coisa'));
        event(new AiDecisionRecorded((int) $this->account->id, 2, self::JID, 'escalou', 'pedir_pix'));
        $this->assertSame(0, $this->contact->tags()->count());

        // Intent certo + RESPONDEU (acima do limiar): aplica com origem ai_intent.
        event(new AiDecisionRecorded((int) $this->account->id, 3, self::JID, 'respondeu', 'pedir_pix'));
        $pivot = $this->contact->tags()->first()->pivot;
        $this->assertSame('ai_intent', $pivot->origin);
        $this->assertSame('pedir_pix', $pivot->origin_ref);
    }

    // ---- escopo por tag ---------------------------------------------------------------

    public function test_regra_com_escopo_por_tag_casa_so_quem_tem_a_tag(): void
    {
        $tag = $this->tag('vip');
        $regra = $this->regra('promo', 'Oferta VIP!', 'tags');
        $regra->tags()->attach($tag->id);

        // SEM a tag: nao casa (silencio).
        $this->receber('promo', 'W1');
        Http::assertNothingSent();

        // COM a tag: casa na PROXIMA mensagem (avaliado na hora).
        $this->contact->tags()->attach($tag->id, ['origin' => 'manual']);
        $this->receber('promo', 'W2');
        Http::assertSent(fn ($r) => $r['text'] === 'Oferta VIP!');

        // Tag removida: para de casar.
        $this->contact->tags()->detach($tag->id);
        Http::fake(['*' => Http::response(['key' => ['id' => 'PMID']], 201)]);
        $this->receber('promo', 'W3');
        Http::assertNothingSent();
    }

    public function test_especificidade_contato_vence_tag_que_vence_global(): void
    {
        $tag = $this->tag('vip');
        $this->contact->tags()->attach($tag->id, ['origin' => 'manual']);

        $global = $this->regra('preco', 'RESPOSTA-GLOBAL');
        $porTag = $this->regra('preco', 'RESPOSTA-TAG', 'tags');
        $porTag->tags()->attach($tag->id);

        // Empate de gatilho: TAG vence GLOBAL.
        $this->receber('qual o preco?', 'W1');
        Http::assertSent(fn ($r) => $r['text'] === 'RESPOSTA-TAG');

        // Contato especifico vence TAG.
        $porContato = $this->regra('preco', 'RESPOSTA-CONTATO', 'contatos');
        $porContato->contacts()->attach($this->contact->id);
        Http::fake(['*' => Http::response(['key' => ['id' => 'PMID']], 201)]);
        $this->receber('qual o preco?', 'W2');
        Http::assertSent(fn ($r) => $r['text'] === 'RESPOSTA-CONTATO');
    }

    public function test_fluxo_com_escopo_por_tag(): void
    {
        $tag = $this->tag('suporte');
        $flow = Flow::create(['account_id' => $this->account->id, 'name' => 'F', 'enabled' => true, 'timeout_seconds' => 600, 'scope' => 'tags']);
        $flow->triggers()->create(['match_type' => 'contains', 'match_value' => 'menu']);
        $root = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'menu', 'message' => 'MENU-DO-FLUXO']);
        $flow->update(['root_node_id' => $root->id]);
        $flow->tags()->attach($tag->id);

        // Sem a tag: fluxo nao entra.
        $this->receber('menu', 'W1');
        Http::assertNothingSent();

        // Com a tag: entra.
        $this->contact->tags()->attach($tag->id, ['origin' => 'manual']);
        $this->receber('menu', 'W2');
        Http::assertSent(fn ($r) => $r['text'] === 'MENU-DO-FLUXO');
    }

    public function test_conflito_detectado_entre_tag_e_global_e_especifica(): void
    {
        $tag = $this->tag('vip');
        $global = $this->regra('horario', 'G');
        $porTag = $this->regra('horario', 'T', 'tags');
        $porTag->tags()->attach($tag->id);
        $porContato = $this->regra('horario', 'C', 'contatos');
        $porContato->contacts()->attach($this->contact->id);

        $conflitos = app(\App\Whatsapp\AutoReply\RuleConflictDetector::class)->conflicts($this->account->id);

        // Regra por TAG colide com a global E com a especifica (aviso, nao bloqueio).
        $idsEmConflitoComTag = collect($conflitos[$porTag->id] ?? [])->pluck('id')->all();
        $this->assertContains($global->id, $idsEmConflitoComTag);
        $this->assertContains($porContato->id, $idsEmConflitoComTag);
    }

    public function test_testador_explica_fora_por_tag_e_casou_por_tag(): void
    {
        $tag = $this->tag('vip');
        $porTag = $this->regra('promo', 'Oferta!', 'tags');
        $porTag->tags()->attach($tag->id);

        // Sem a tag: nada casa + explicacao "fora por tag".
        $res = app(\App\Whatsapp\AutoReply\RuleTester::class)->test($this->account->id, $this->channel->id, 'promo', $this->contact->id);
        $this->assertFalse($res['matched']);
        $this->assertStringContainsString('vip', implode(' ', $res['fora_por_tag']));

        // Com a tag: casa e informa "casou por tag".
        $this->contact->tags()->attach($tag->id, ['origin' => 'manual']);
        $res = app(\App\Whatsapp\AutoReply\RuleTester::class)->test($this->account->id, $this->channel->id, 'promo', $this->contact->id);
        $this->assertTrue($res['matched']);
        $this->assertSame('vip', $res['casou_por_tag']);
    }

    // ---- guarda S5: segredo JAMAIS por tag ------------------------------------------------

    public function test_s5_regra_com_senha_nao_pode_escopo_por_tag_no_writer(): void
    {
        app(SecretVault::class)->put($this->account->id, 'wifi', 'segredoDoWifi123');
        $tag = $this->tag('vip');

        $res = app(RuleWriter::class)->save($this->account->id, [
            'triggers' => [['type' => 'contains', 'value' => 'senha do wifi']],
            'responses' => ['A senha e {senha:wifi}'],
            'enabled' => true, 'cooldown_mode' => 'global', 'cooldown_minutes' => null,
            'scope' => 'tags', 'contact_ids' => [], 'tag_ids' => [$tag->id],
            'ai_match_enabled' => false, 'ai_examples' => [],
        ]);

        $this->assertNull($res['rule']);
        $this->assertArrayHasKey('scope', $res['errors']);
        $this->assertStringContainsString('tag', $res['errors']['scope']);
        $this->assertDatabaseCount('auto_reply_rules', 0);
    }

    public function test_s5_bloqueio_tambem_na_ui_de_regras(): void
    {
        app(SecretVault::class)->put($this->account->id, 'wifi', 'segredoDoWifi123');
        $tag = $this->tag('vip');

        Livewire::test(Regras::class)
            ->call('novo')
            ->set('triggers.0.value', 'senha do wifi')
            ->set('responses.0', 'A senha e {senha:wifi}')
            ->set('scope', 'tags')
            ->set('scopeTagIds', [$tag->id])
            ->call('save')
            ->assertHasErrors('scope');

        $this->assertDatabaseCount('auto_reply_rules', 0);
    }

    // ---- kanban / gerenciamento -----------------------------------------------------------

    public function test_kanban_filtra_por_tag_e_mostra_chips(): void
    {
        $tag = $this->tag('vip', 'purple');
        $this->contact->tags()->attach($tag->id, ['origin' => 'manual']);
        $outro = Contact::create(['account_id' => $this->account->id, 'remote_jid' => '5541888880000@s.whatsapp.net', 'push_name' => 'Sem Tag']);
        $this->receber('oi', 'W1');
        // Card do segundo contato (sem tag).
        $novo = $this->board->columns()->where('slug', 'novo')->first();
        \App\Models\Card::create(['account_id' => $this->account->id, 'board_id' => $this->board->id, 'contact_id' => $outro->id, 'column_id' => $novo->id]);

        Livewire::test(Kanban::class)
            ->assertSee('vip')            // chip renderiza
            ->assertSee('Sem Tag')
            ->set('filterTagId', $tag->id)
            ->assertSee('Cliente')
            ->assertDontSee('Sem Tag');   // filtrado
    }

    public function test_gerenciar_tags_renomeia_e_exclui_com_uso(): void
    {
        $tag = $this->tag('velha');
        $this->contact->tags()->attach($tag->id, ['origin' => 'manual']);

        Livewire::test(Contatos::class)
            ->call('openTags')
            ->set('tagNames.' . $tag->id, 'nova')
            ->set('tagColors.' . $tag->id, 'emerald')
            ->call('saveTags')
            ->assertHasNoErrors();
        $tag->refresh();
        $this->assertSame('nova', $tag->name);
        $this->assertSame('emerald', $tag->color);

        // Excluir com confirmacao: pivo some junto (cascade).
        Livewire::test(Contatos::class)
            ->call('openTags')
            ->call('confirmDeleteTag', $tag->id)
            ->call('deleteTagConfirmed');
        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
        $this->assertDatabaseCount('contact_tag', 0);
    }
}
