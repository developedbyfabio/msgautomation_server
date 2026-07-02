<?php

namespace Tests\Feature;

use App\Ai\AiAnswer;
use App\Ai\AiAnswerRequest;
use App\Ai\AiClassification;
use App\Ai\AiClassificationRequest;
use App\Contracts\AiClassifier;
use App\Jobs\ProcessIncomingWhatsappMessage;
use App\Livewire\Conhecimento;
use App\Livewire\Revisao;
use App\Models\Account;
use App\Models\AiDecision;
use App\Models\AutoReplyLog;
use App\Models\AutoReplyRule;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\IncomingMessage;
use App\Models\Knowledge;
use App\Models\PendingApproval;
use App\Whatsapp\Secrets\SecretVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Camada 3/5 Fatia 4 — "virar regra" / "virar entrada da base" (loop de aprendizado).
 * Driver MOCKADO, HTTP mockado. Provas: promocao cria regra/entrada pelo caminho
 * OFICIAL (RuleWriter/KnowledgeWriter — mesmas guardas do CRUD), senha nunca vira
 * global/irrestrita, conflito avisa sem bloquear, promocao e unica e auditavel, e a
 * regra promovida casa a mesma mensagem DETERMINISTICAMENTE (segunda vez sem IA).
 */
class PromocaoTest extends TestCase
{
    use RefreshDatabase;

    private const JID = '5541999990000@s.whatsapp.net';
    private Account $account;
    private Channel $channel;
    private Contact $contact;

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
            'ai_enabled' => true, 'ai_confidence_threshold' => 0.75,
        ]);
        $this->contact = Contact::create([
            'account_id' => $this->account->id, 'remote_jid' => self::JID,
            'auto_reply_mode' => 'on', 'ai_enabled' => true, 'ai_mode' => 'intencao', 'push_name' => 'Cliente',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function incoming(string $texto, string $id = 'IM1'): IncomingMessage
    {
        return IncomingMessage::create([
            'account_id' => $this->account->id, 'channel_id' => $this->channel->id,
            'instance' => $this->channel->instance, 'evolution_message_id' => $id,
            'remote_jid' => self::JID, 'from_me' => false, 'push_name' => 'Cliente',
            'type' => 'conversation', 'text' => $texto, 'raw_payload' => ['x' => 1], 'received_at' => now(),
        ]);
    }

    private function pendencia(array $extra = []): PendingApproval
    {
        $im = $extra['incoming'] ?? $this->incoming('qual o preco do produto?', 'IM-' . uniqid());
        unset($extra['incoming']);

        return PendingApproval::create(array_merge([
            'account_id' => $this->account->id,
            'contact_id' => $this->contact->id,
            'incoming_message_id' => $im->id,
            'remote_jid' => self::JID,
            'suggested_response' => 'Custa 100 reais.',
            'origin' => 'regra',
            'reason' => 'baixa_confianca',
            'intent' => 'preco',
            'confidence' => 0.5,
            'status' => 'pending',
        ], $extra));
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

    // ---- virar regra -----------------------------------------------------------

    public function test_virar_regra_cria_regra_completa_e_trava_a_pendencia(): void
    {
        $p = $this->pendencia();

        Livewire::test(Revisao::class)
            ->call('startPromote', 'regra', 'pendencia', $p->id)
            // Modal PRE-PREENCHIDO: gatilho = mensagem original; resposta = sugestao;
            // escopo conservador (so o contato); IA casa parecidas ja marcado.
            ->assertSet('pTrigger', 'qual o preco do produto?')
            ->assertSet('pResponse', 'Custa 100 reais.')
            ->assertSet('pScope', 'contatos')
            ->assertSet('pAiMatch', true)
            ->call('confirmPromoteRule')
            ->assertHasNoErrors();

        $rule = AutoReplyRule::latest('id')->first();
        $this->assertNotNull($rule);
        $this->assertTrue((bool) $rule->enabled);
        $this->assertSame('contatos', $rule->scope);
        $this->assertTrue((bool) $rule->ai_match_enabled);
        $this->assertSame([['type' => 'contains', 'value' => 'qual o preco do produto?']],
            $rule->triggerList()->map(fn ($t) => ['type' => $t['type'], 'value' => $t['value']])->all());
        $this->assertSame(['Custa 100 reais.'], $rule->responseList()->all());
        // Frase-exemplo = a mensagem original (alimenta o casamento por IA).
        $this->assertSame(['qual o preco do produto?'], $rule->aiExampleList());
        // Escopo especifico com o contato da pendencia.
        $this->assertTrue($rule->contacts->contains('id', $this->contact->id));

        // Auditoria do loop: pendencia promovida e travada.
        $p->refresh();
        $this->assertSame($rule->id, (int) $p->promoted_rule_id);
        $this->assertTrue($p->isPromoted());
        Http::assertNothingSent(); // promover NAO envia nada
    }

    public function test_regra_promovida_casa_a_mesma_mensagem_sem_ia(): void
    {
        $p = $this->pendencia();
        Livewire::test(Revisao::class)
            ->call('startPromote', 'regra', 'pendencia', $p->id)
            ->call('confirmPromoteRule')
            ->assertHasNoErrors();

        // PROVA DO LOOP: a mesma mensagem chega de novo -> regra deterministica
        // responde; a IA nem e consultada (gratis e instantaneo).
        $fake = new FakePromocaoAi();
        $this->app->instance(AiClassifier::class, $fake);

        $this->receber('qual o preco do produto?', 'W2');

        Http::assertSent(fn ($r) => $r['text'] === 'Custa 100 reais.');
        $this->assertSame(0, $fake->calls);
        $this->assertSame(0, AiDecision::count());
    }

    public function test_senha_na_resposta_bloqueia_escopo_global(): void
    {
        app(SecretVault::class)->put($this->account->id, 'wifi', 'segredoDoWifi123');
        $p = $this->pendencia(['suggested_response' => 'A senha e {senha:wifi}', 'reason' => 'contem_senha']);

        Livewire::test(Revisao::class)
            ->call('startPromote', 'regra', 'pendencia', $p->id)
            ->set('pScope', 'global')
            ->call('confirmPromoteRule')
            ->assertHasErrors('pScope');

        $this->assertDatabaseCount('auto_reply_rules', 0);
        $this->assertNull($p->fresh()->promoted_rule_id);
    }

    public function test_senha_com_escopo_do_contato_promove(): void
    {
        app(SecretVault::class)->put($this->account->id, 'wifi', 'segredoDoWifi123');
        $p = $this->pendencia(['suggested_response' => 'A senha e {senha:wifi}', 'reason' => 'contem_senha']);

        Livewire::test(Revisao::class)
            ->call('startPromote', 'regra', 'pendencia', $p->id)
            ->call('confirmPromoteRule')
            ->assertHasNoErrors();

        $rule = AutoReplyRule::latest('id')->first();
        $this->assertSame('contatos', $rule->scope);
        $this->assertTrue($rule->contacts->contains('id', $this->contact->id));
        // O template guarda o placeholder; o valor NUNCA e persistido na regra.
        $this->assertSame(['A senha e {senha:wifi}'], $rule->responseList()->all());
    }

    public function test_conflito_com_regra_existente_avisa_sem_bloquear(): void
    {
        // Regra pre-existente que casa a mesma mensagem (gatilho "preco").
        $existente = AutoReplyRule::create(['account_id' => $this->account->id, 'match_type' => 'contains', 'match_value' => 'preco', 'response_text' => 'Tabela no site.', 'enabled' => true]);
        $existente->triggers()->create(['match_type' => 'contains', 'match_value' => 'preco']);
        $existente->responses()->create(['response_text' => 'Tabela no site.']);

        $p = $this->pendencia();

        Livewire::test(Revisao::class)
            ->call('startPromote', 'regra', 'pendencia', $p->id)
            ->call('confirmPromoteRule')
            ->assertHasNoErrors()
            // Aviso (nao bloqueio): toast menciona a sobreposicao.
            ->assertDispatched('toast', fn ($nome, $params) => str_contains((string) ($params['message'] ?? ''), 'sobreposicao'));

        // A regra FOI criada mesmo com o aviso.
        $this->assertSame(2, AutoReplyRule::count());
        $this->assertNotNull($p->fresh()->promoted_rule_id);
    }

    public function test_promocao_e_unica_por_item(): void
    {
        $p = $this->pendencia();
        Livewire::test(Revisao::class)
            ->call('startPromote', 'regra', 'pendencia', $p->id)
            ->call('confirmPromoteRule')
            ->assertHasNoErrors();
        $this->assertSame(1, AutoReplyRule::count());

        // Segunda promocao: o modal nem abre (promovida trava) e nada e criado.
        Livewire::test(Revisao::class)
            ->call('startPromote', 'regra', 'pendencia', $p->id)
            ->assertSet('promoteKind', '')
            ->call('confirmPromoteRule');

        $this->assertSame(1, AutoReplyRule::count());
    }

    // ---- virar entrada da base ----------------------------------------------------

    public function test_virar_entrada_da_base_cria_entrada_restrita_ao_contato(): void
    {
        $p = $this->pendencia();

        Livewire::test(Revisao::class)
            ->call('startPromote', 'base', 'pendencia', $p->id)
            ->assertSet('pTitle', 'preco') // intent como titulo sugerido
            ->assertSet('pContent', 'Custa 100 reais.')
            ->assertSet('pSensitivity', 'medium')
            ->assertSet('pRestrict', true)
            ->call('confirmPromoteKnowledge')
            ->assertHasNoErrors();

        $k = Knowledge::latest('id')->first();
        $this->assertSame('preco', $k->title);
        $this->assertSame('Custa 100 reais.', $k->content);
        $this->assertSame('medium', $k->sensitivity);
        $this->assertTrue((bool) $k->active);
        $this->assertTrue($k->contacts->contains('id', $this->contact->id));

        $p->refresh();
        $this->assertSame($k->id, (int) $p->promoted_knowledge_id);
        $this->assertTrue($p->isPromoted());
    }

    public function test_virar_base_com_senha_exige_restricao_de_contato(): void
    {
        app(SecretVault::class)->put($this->account->id, 'wifi', 'segredoDoWifi123');
        $p = $this->pendencia(['suggested_response' => 'A senha e {senha:wifi}']);

        Livewire::test(Revisao::class)
            ->call('startPromote', 'base', 'pendencia', $p->id)
            ->set('pRestrict', false)
            ->call('confirmPromoteKnowledge')
            ->assertHasErrors('pRestrict');

        $this->assertDatabaseCount('knowledge', 0);

        // Com a restricao mantida, promove.
        Livewire::test(Revisao::class)
            ->call('startPromote', 'base', 'pendencia', $p->id)
            ->call('confirmPromoteKnowledge')
            ->assertHasNoErrors();
        $this->assertDatabaseCount('knowledge', 1);
    }

    public function test_guarda_de_senha_vale_tambem_no_crud_do_conhecimento(): void
    {
        // Mesma guarda no caminho oficial: criar entrada com {senha:} sem restringir
        // contatos e bloqueado tambem no /conhecimento (KnowledgeWriter compartilhado).
        Livewire::test(Conhecimento::class)
            ->call('novo')
            ->set('title', 'Wifi')
            ->set('content', 'A senha e {senha:wifi}')
            ->call('save')
            ->assertHasErrors('contactIds');

        $this->assertDatabaseCount('knowledge', 0);
    }

    // ---- promocao a partir de decisao (IA respondeu sozinha) -------------------------

    public function test_promover_decisao_respondida_usa_o_texto_enviado(): void
    {
        $im = $this->incoming('vcs abrem sabado?', 'IM9');
        $log = AutoReplyLog::create([
            'account_id' => $this->account->id, 'channel_id' => $this->channel->id,
            'incoming_message_id' => $im->id, 'remote_jid' => self::JID, 'mode' => 'auto',
            'response_text' => 'Abrimos sabado ate 12h.', 'status' => 'sent', 'sent_at' => now(),
        ]);
        $d = AiDecision::create([
            'account_id' => $this->account->id, 'contact_id' => $this->contact->id,
            'incoming_message_id' => $im->id, 'remote_jid' => self::JID,
            'acao' => 'respondeu', 'origem' => 'base', 'confidence' => 0.9,
        ]);

        Livewire::test(Revisao::class)
            ->call('startPromote', 'regra', 'decisao', $d->id)
            ->assertSet('pTrigger', 'vcs abrem sabado?')
            ->assertSet('pResponse', 'Abrimos sabado ate 12h.') // texto que FOI enviado
            ->call('confirmPromoteRule')
            ->assertHasNoErrors();

        $this->assertSame(1, AutoReplyRule::count());
        $this->assertNotNull($d->fresh()->promoted_rule_id);
    }

    public function test_decisao_que_nao_respondeu_nao_promove_pela_aba(): void
    {
        // Escaladas tem pendencia propria; silenciadas nao tem o que promover.
        $im = $this->incoming('x', 'IM8');
        $d = AiDecision::create([
            'account_id' => $this->account->id, 'incoming_message_id' => $im->id,
            'remote_jid' => self::JID, 'acao' => 'silenciou', 'origem' => 'regra',
        ]);

        Livewire::test(Revisao::class)
            ->call('startPromote', 'regra', 'decisao', $d->id)
            ->assertSet('promoteKind', '');

        $this->assertDatabaseCount('auto_reply_rules', 0);
    }

    // ---- isolamento entre contas ------------------------------------------------------

    public function test_promocao_de_item_de_outra_conta_e_no_op(): void
    {
        $accountB = Account::create(['name' => 'B']);
        $contactB = Contact::create(['account_id' => $accountB->id, 'remote_jid' => '555@s.whatsapp.net']);
        $pB = PendingApproval::create([
            'account_id' => $accountB->id, 'contact_id' => $contactB->id,
            'remote_jid' => '555@s.whatsapp.net', 'suggested_response' => 'RESP-B',
            'origin' => 'regra', 'reason' => 'baixa_confianca', 'status' => 'pending',
        ]);

        // A tela opera na conta-ancora (A): item da B nem abre o modal.
        Livewire::test(Revisao::class)
            ->call('startPromote', 'regra', 'pendencia', $pB->id)
            ->assertSet('promoteKind', '')
            ->call('confirmPromoteRule');

        $this->assertDatabaseCount('auto_reply_rules', 0);
        $this->assertNull($pB->fresh()->promoted_rule_id);
    }
}

/** Driver falso — conta chamadas pra provar que a regra promovida dispensa a IA. */
class FakePromocaoAi implements AiClassifier
{
    public int $calls = 0;

    public function classify(AiClassificationRequest $request): AiClassification
    {
        $this->calls++;

        return new AiClassification('', 0.9, null, false, false, 'nenhuma');
    }

    public function answer(AiAnswerRequest $request): AiAnswer
    {
        $this->calls++;

        return AiAnswer::unknown('sem_mock');
    }
}
