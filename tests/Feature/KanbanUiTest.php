<?php

namespace Tests\Feature;

use App\Events\FlowNodeReached;
use App\Jobs\ProcessIncomingWhatsappMessage;
use App\Livewire\Kanban;
use App\Models\Account;
use App\Models\AutoReplySetting;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\BoardRule;
use App\Models\Card;
use App\Models\CardTransition;
use App\Models\Channel;
use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kanban K-2 — UI do board (/kanban). HTTP mockado (nunca envio real). Provas:
 * render/busca, movimento manual (cause=manual, no-op na mesma coluna, historico),
 * colunas (renomear preserva slug e as regras seguem movendo; excluir com guardas),
 * regras editaveis com EFEITO REAL no motor (first-match, desativar default),
 * navegacao card->conversa. Observador puro intacto.
 */
class KanbanUiTest extends TestCase
{
    use RefreshDatabase;

    private const JID = '5541999990000@s.whatsapp.net';
    private Account $account;
    private Channel $channel;
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
        $this->board = Board::withoutAccountScope()->where('account_id', $this->account->id)->where('is_default', true)->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function receber(string $texto, string $id = 'W1', ?string $jid = null): void
    {
        (new ProcessIncomingWhatsappMessage([
            'event' => 'messages.upsert', 'instance' => 'fabio-pessoal',
            'data' => [
                'key' => ['id' => $id, 'fromMe' => false, 'remoteJid' => $jid ?: self::JID],
                'pushName' => 'Cliente Kanban', 'messageType' => 'conversation',
                'message' => ['conversation' => $texto], 'messageTimestamp' => 1782699162,
            ],
        ]))->handle(
            app(\App\Contracts\WhatsappGateway::class),
            app(\App\Whatsapp\AutoReply\RuleMatcher::class),
            app(\App\Whatsapp\AutoReply\AntiBanGuard::class),
        );
    }

    private function col(string $slug): BoardColumn
    {
        return $this->board->columns()->where('slug', $slug)->firstOrFail();
    }

    private function card(): Card
    {
        return Card::withoutAccountScope()->where('board_id', $this->board->id)->firstOrFail();
    }

    // ---- render / busca -----------------------------------------------------------

    public function test_render_mostra_card_na_coluna_certa_e_busca_filtra(): void
    {
        $this->receber('oi'); // card em Novo

        Livewire::test(Kanban::class)
            ->assertSee('Cliente Kanban')
            ->assertSee('Novo')
            ->assertSee('Em atendimento');

        Livewire::test(Kanban::class)
            ->set('search', 'nao-existe-ninguem-assim')
            ->assertDontSee('Cliente Kanban');

        Livewire::test(Kanban::class)
            ->set('search', 'kanban')
            ->assertSee('Cliente Kanban');
    }

    // ---- movimento manual -----------------------------------------------------------

    public function test_mover_manual_registra_transicao_manual(): void
    {
        // Fatia 11: a mensagem sem resposta ja deixou o card em 'aguardando' —
        // o movimento manual do teste vai pra 'resolvido' (coluna diferente).
        $this->receber('oi');
        $card = $this->card();
        $this->assertSame($this->col('aguardando')->id, (int) $card->column_id);

        Livewire::test(Kanban::class)->call('moveCard', $card->id, $this->col('resolvido')->id);

        $this->assertSame($this->col('resolvido')->id, (int) $card->fresh()->column_id);
        $this->assertDatabaseHas('card_transitions', [
            'card_id' => $card->id, 'from_column_id' => $this->col('aguardando')->id,
            'to_column_id' => $this->col('resolvido')->id, 'cause' => 'manual', 'board_rule_id' => null,
        ]);
        // Observador puro: mover card NAO envia nada.
        Http::assertNothingSent();
    }

    public function test_mover_pra_mesma_coluna_e_noop(): void
    {
        $this->receber('oi');
        $card = $this->card(); // fatia 11: card esta em 'aguardando'
        $antes = CardTransition::where('card_id', $card->id)->count();

        Livewire::test(Kanban::class)->call('moveCard', $card->id, (int) $card->column_id);

        $this->assertSame($antes, CardTransition::where('card_id', $card->id)->count());
    }

    public function test_historico_lista_transicoes_com_causa(): void
    {
        $this->receber('oi');
        $card = $this->card();
        Livewire::test(Kanban::class)->call('moveCard', $card->id, $this->col('resolvido')->id);

        Livewire::test(Kanban::class)
            ->call('showHistory', $card->id)
            ->assertSee('manual')
            ->assertSee('regra')
            ->assertSee('Resolvido');
    }

    // ---- colunas ------------------------------------------------------------------------

    public function test_renomear_preserva_slug_e_regras_seguem_movendo(): void
    {
        $novo = $this->col('novo');

        Livewire::test(Kanban::class)
            ->call('openColumns')
            ->set('colNames.' . $novo->id, 'Caixa de entrada')
            ->call('saveColumns')
            ->assertHasNoErrors();

        $novo->refresh();
        $this->assertSame('Caixa de entrada', $novo->name);
        $this->assertSame('novo', $novo->slug); // slug INTACTO

        // A regra default (que referencia a coluna) segue CRIANDO nela apos renomear
        // (slug intacto — provado pela transicao)...
        $this->receber('oi', 'W9');
        $this->assertDatabaseHas('card_transitions', [
            'to_column_id' => $novo->id, 'cause' => 'regra', 'event_type' => 'mensagem_recebida',
        ]);
        // ...e a Fatia 11 seguiu com o card sem resposta pra 'aguardando'.
        $this->assertSame($this->col('aguardando')->id, (int) $this->card()->column_id);
    }

    public function test_reordenar_colunas_persiste(): void
    {
        $novo = $this->col('novo');

        Livewire::test(Kanban::class)->call('moveColumn', $novo->id, 'down');

        $this->assertSame(1, (int) $novo->fresh()->position);
        $this->assertSame(0, (int) $this->col('em_atendimento')->position);
    }

    public function test_adicionar_coluna_custom_e_excluir_vazia(): void
    {
        Livewire::test(Kanban::class)
            ->call('openColumns')
            ->set('newColName', 'Orcamento')
            ->call('addColumn')
            ->assertHasNoErrors();

        $col = $this->board->columns()->where('name', 'Orcamento')->firstOrFail();
        $this->assertSame('orcamento', $col->slug);

        Livewire::test(Kanban::class)->call('deleteColumn', $col->id);
        $this->assertDatabaseMissing('board_columns', ['id' => $col->id]);
    }

    public function test_excluir_coluna_system_ou_com_cards_e_bloqueado(): void
    {
        // System: bloqueada.
        Livewire::test(Kanban::class)
            ->call('deleteColumn', $this->col('novo')->id)
            ->assertDispatched('toast', fn ($n, $p) => str_contains((string) ($p['message'] ?? ''), 'padrao'));
        $this->assertDatabaseHas('board_columns', ['id' => $this->col('novo')->id]);

        // Custom com card: bloqueada.
        $custom = BoardColumn::create(['board_id' => $this->board->id, 'slug' => 'temp', 'name' => 'Temp', 'position' => 99]);
        $this->receber('oi');
        Card::withoutAccountScope()->where('board_id', $this->board->id)->update(['column_id' => $custom->id]);

        Livewire::test(Kanban::class)
            ->call('deleteColumn', $custom->id)
            ->assertDispatched('toast', fn ($n, $p) => str_contains((string) ($p['message'] ?? ''), 'cards'));
        $this->assertDatabaseHas('board_columns', ['id' => $custom->id]);
    }

    // ---- regras de movimento ---------------------------------------------------------------

    public function test_criar_regra_nova_tem_efeito_real_no_motor(): void
    {
        // Coluna custom + regra: fluxo_no -> "Em fluxo" (evento sem default).
        $col = BoardColumn::create(['board_id' => $this->board->id, 'slug' => 'em_fluxo', 'name' => 'Em fluxo', 'position' => 9]);

        Livewire::test(Kanban::class)
            ->call('startRuleCreate')
            ->set('rEvent', 'fluxo_no')
            ->set('rCondition', 'none')
            ->set('rToColumnId', $col->id)
            ->call('saveRule')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('board_rules', ['event_type' => 'fluxo_no', 'to_column_id' => $col->id, 'is_default' => false]);

        // EFEITO REAL: evento dispara -> motor move o card pra coluna custom.
        Contact::create(['account_id' => $this->account->id, 'remote_jid' => self::JID]);
        $this->receber('oi', 'W1'); // cria card em Novo
        event(new FlowNodeReached((int) $this->account->id, 1, self::JID, 1, 'active'));

        $this->assertSame($col->id, (int) $this->card()->column_id);
        $this->assertDatabaseHas('card_transitions', ['event_type' => 'fluxo_no', 'to_column_id' => $col->id, 'cause' => 'regra']);
    }

    public function test_desativar_regra_default_para_o_movimento_com_confirmacao(): void
    {
        $regraNovo = BoardRule::withoutAccountScope()->where('board_id', $this->board->id)
            ->where('event_type', 'mensagem_recebida')->where('position', 0)->firstOrFail();

        Livewire::test(Kanban::class)
            ->call('toggleRule', $regraNovo->id)
            ->assertSet('confirmingDefaultRuleId', $regraNovo->id) // pediu confirmacao
            ->call('confirmDefaultAction');

        $this->assertFalse((bool) $regraNovo->fresh()->active);

        // Sem a regra "cria em Novo": NENHUMA criacao/movimento POR REGRA...
        $this->receber('oi', 'W1');
        $this->assertSame(0, CardTransition::where('cause', 'regra')->count());
        // ...mas o movimento DETERMINISTICO da Fatia 11 (sem resposta) segue criando
        // a pendencia em 'aguardando' — acao de SISTEMA, nao regra (mesmo padrao do
        // handoff da Fatia 5: nao depende de BoardRule e nao e desligavel por ela).
        $this->assertSame($this->col('aguardando')->id, (int) $this->card()->column_id);
        $this->assertDatabaseHas('card_transitions', ['cause' => 'sem_resposta']);
    }

    public function test_reordenar_muda_o_first_match(): void
    {
        // Duas regras concorrentes pro MESMO evento/condicao: vence a de cima.
        $aguardando = $this->col('aguardando');
        $nova = BoardRule::create([
            'account_id' => $this->account->id, 'board_id' => $this->board->id,
            'event_type' => 'mensagem_recebida', 'conditions' => ['card' => 'absent'],
            'to_column_id' => $aguardando->id, 'active' => true, 'is_default' => false, 'position' => 99,
        ]);

        // Sobe a nova ate o topo (4 posicoes acima das default).
        $c = Livewire::test(Kanban::class);
        foreach (range(1, 4) as $i) {
            $c->call('moveRule', $nova->id, 'up');
        }

        $this->receber('oi', 'W1');
        $this->assertSame($aguardando->id, (int) $this->card()->column_id); // a nova venceu
    }

    public function test_validacao_regra_sem_destino_nao_salva(): void
    {
        Livewire::test(Kanban::class)
            ->call('startRuleCreate')
            ->set('rEvent', 'mensagem_recebida')
            ->set('rToColumnId', null)
            ->call('saveRule')
            ->assertHasErrors('rToColumnId');

        // Destino de OUTRA conta tambem e recusado.
        $outraConta = Account::create(['name' => 'B']);
        $boardB = Board::withoutAccountScope()->where('account_id', $outraConta->id)->first();
        $colB = $boardB->columns()->first();

        Livewire::test(Kanban::class)
            ->call('startRuleCreate')
            ->set('rEvent', 'mensagem_recebida')
            ->set('rToColumnId', $colB->id)
            ->call('saveRule')
            ->assertHasErrors('rToColumnId');
    }

    // ---- navegacao ------------------------------------------------------------------------------

    public function test_card_linka_pra_conversa_do_contato(): void
    {
        $this->receber('oi');

        // O card aponta pra /conversas?jid=...
        Livewire::test(Kanban::class)
            ->assertSee(route('conversas', ['jid' => self::JID]), false);

        // E o /conversas abre com o contato selecionado via query param.
        $this->get(route('conversas', ['jid' => self::JID]));
        Livewire::withQueryParams(['jid' => self::JID]);
        $user = \App\Models\User::create(['name' => 'F', 'email' => 'f@t.local', 'password' => bcrypt('x')]);
        $this->actingAs($user);
        $this->get('/conversas?jid=' . urlencode(self::JID))->assertOk()->assertSee('Cliente Kanban');
    }
}
