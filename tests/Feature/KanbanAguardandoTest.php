<?php

namespace Tests\Feature;

use App\Enums\OperationMode;
use App\Jobs\ProcessIncomingWhatsappMessage;
use App\Kanban\BoardEngine;
use App\Kanban\BoardProvisioner;
use App\Models\Account;
use App\Models\AutoReplyRule;
use App\Models\AutoReplySetting;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Card;
use App\Models\CardTransition;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\FlowOption;
use App\Models\UnmatchedMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fatia 11 — coluna "Aguardando resposta" como PENDENCIA HUMANA:
 *  - handoff move o card pra 'aguardando' e a despedida enviada depois NAO o
 *    regride pra em_atendimento (corrida resposta_enviada x handoff resolvida
 *    por supressao: o AutoReplySent da despedida viaja com handoff=true e o
 *    listener do Kanban nao aplica a regra);
 *  - unmatched (robo sem resposta, ambos os modos) move o card pra 'aguardando'
 *    best-effort, SEM mudar nenhuma decisao de resposta;
 *  - resposta normal (regra/fluxo sem handoff) segue movendo pra em_atendimento.
 * A coluna 'aguardando' existe desde a K-1 (D4) — nao ha coluna nova/backfill;
 * o primeiro teste fixa a garantia do provisioner.
 */
class KanbanAguardandoTest extends TestCase
{
    use RefreshDatabase;

    private const JID = '5541988887777@s.whatsapp.net';

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 7, 5, 10, 0, 0, 'America/Sao_Paulo'));
        Http::fake(['*' => Http::response(['key' => ['id' => 'PMID']], 201)]);

        $this->account = Account::create(['name' => 'T']); // booted() provisiona o board default
        Channel::create(['account_id' => $this->account->id, 'instance' => 'inst-t', 'status' => 'connected']);
        AutoReplySetting::create([
            'account_id' => $this->account->id, 'enabled' => true, 'reply_policy' => 'all',
            'window_start' => '08:00:00', 'window_end' => '20:00:00',
            'min_interval_seconds' => 0, 'per_minute_cap' => 100, 'per_day_cap' => 100,
            'contact_rate_seconds' => 0, 'delay_min_seconds' => 0, 'delay_max_seconds' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function receber(string $texto, string $id, string $instance = 'inst-t', string $jid = self::JID): void
    {
        (new ProcessIncomingWhatsappMessage([
            'event' => 'messages.upsert', 'instance' => $instance,
            'data' => [
                'key' => ['id' => $id, 'fromMe' => false, 'remoteJid' => $jid],
                'pushName' => 'Cliente', 'messageType' => 'conversation',
                'message' => ['conversation' => $texto], 'messageTimestamp' => 1782699162,
            ],
        ]))->handle(
            app(\App\Contracts\WhatsappGateway::class),
            app(\App\Whatsapp\AutoReply\RuleMatcher::class),
            app(\App\Whatsapp\AutoReply\AntiBanGuard::class),
        );
    }

    private function fluxoComHandoff(): void
    {
        $flow = Flow::create(['account_id' => $this->account->id, 'name' => 'Atendimento', 'enabled' => true, 'timeout_seconds' => 600]);
        $root = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'menu', 'message' => 'MENU: 1-Atendente']);
        $handoff = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'handoff', 'message' => 'Um atendente vai te responder.']);
        FlowOption::create(['flow_node_id' => $root->id, 'input' => '1', 'label' => 'Atendente', 'next_node_id' => $handoff->id, 'ordem' => 1]);
        $flow->update(['root_node_id' => $root->id]);
        AutoReplySetting::withoutAccountScope()->where('account_id', $this->account->id)
            ->update(['operation_mode' => OperationMode::Auto, 'default_flow_id' => $flow->id]);
    }

    private function colId(string $slug): int
    {
        $board = Board::withoutAccountScope()->where('account_id', $this->account->id)->where('is_default', true)->first();

        return (int) BoardColumn::query()->where('board_id', $board->id)->where('slug', $slug)->value('id');
    }

    private function card(): ?Card
    {
        $board = Board::withoutAccountScope()->where('account_id', $this->account->id)->where('is_default', true)->first();

        return Card::withoutAccountScope()->where('board_id', $board->id)->first();
    }

    // ---- Coluna (garantia do provisioner; existe desde a K-1) ----------------

    public function test_board_default_nasce_com_aguardando_logo_apos_em_atendimento(): void
    {
        $board = Board::withoutAccountScope()->where('account_id', $this->account->id)->where('is_default', true)->first();
        $slugs = $board->columns()->orderBy('position')->pluck('slug')->all();

        $this->assertSame(['novo', 'em_atendimento', 'aguardando', 'resolvido', 'reativacao'], $slugs);

        // Idempotencia: re-provisionar a MESMA conta nao duplica nada (no-op).
        app(BoardProvisioner::class)->ensureDefaultBoard($this->account->id);
        $this->assertSame(1, Board::withoutAccountScope()->where('account_id', $this->account->id)->count());
        $this->assertSame(5, $board->columns()->count());
    }

    // ---- Handoff -> aguardando (com a PROVA da corrida) ----------------------

    public function test_handoff_termina_em_aguardando_mesmo_apos_o_envio_da_despedida(): void
    {
        $this->fluxoComHandoff();

        // Passo 1 — menu enviado (resposta normal de fluxo): card vai pra
        // em_atendimento pela regra resposta_enviada (regressao: regra segue viva).
        $this->receber('oi', 'K1');
        $this->assertSame($this->colId('em_atendimento'), (int) $this->card()->column_id);

        // Passo 2 — handoff COMPLETO (fila sync nos testes: motor move o card,
        // job de envio roda, Sender ENVIA a despedida e emite resposta_enviada).
        $this->receber('1', 'K2');
        $this->assertDatabaseHas('auto_reply_logs', ['status' => 'sent', 'response_text' => 'Um atendente vai te responder.']);

        // A PROVA: o card TERMINA em aguardando — a despedida nao o regrediu.
        $this->assertSame($this->colId('aguardando'), (int) $this->card()->column_id);

        // E a despedida NAO gerou transicao resposta_enviada (supressao na emissao):
        // a ultima transicao do card e a do handoff, para aguardando.
        $ultima = CardTransition::query()->where('card_id', $this->card()->id)->latest('id')->first();
        $this->assertSame('handoff', $ultima->cause);
        $this->assertSame($this->colId('aguardando'), (int) $ultima->to_column_id);
        $this->assertSame(0, CardTransition::query()
            ->where('card_id', $this->card()->id)
            ->where('event_type', 'resposta_enviada')
            ->where('to_column_id', $this->colId('em_atendimento'))
            ->where('id', '>', $ultima->id)->count());
    }

    public function test_resposta_normal_de_regra_continua_movendo_para_em_atendimento(): void
    {
        AutoReplyRule::create([
            'account_id' => $this->account->id, 'match_type' => 'contains', 'match_value' => 'preco',
            'response_text' => 'Tabela de precos...', 'enabled' => true, 'priority' => 0,
        ]);

        $this->receber('qual o preco?', 'K3');

        $this->assertDatabaseHas('auto_reply_logs', ['status' => 'sent', 'response_text' => 'Tabela de precos...']);
        $this->assertSame($this->colId('em_atendimento'), (int) $this->card()->column_id); // regra intacta
    }

    // ---- Unmatched -> aguardando (ambos os modos, decisao de resposta intacta)

    public function test_unmatched_modo_pessoal_move_card_para_aguardando_sem_responder(): void
    {
        // Modo pessoal (default), nenhuma regra/fluxo: robo em silencio.
        $this->receber('mensagem sem match', 'K4');

        // Decisao de resposta IDENTICA: silencio + registro do unmatched.
        $this->assertSame(0, \App\Models\AutoReplyLog::withoutAccountScope()->count());
        $this->assertSame(1, UnmatchedMessage::withoutAccountScope()->where('account_id', $this->account->id)->count());

        // Card em aguardando, com a causa propria.
        $card = $this->card();
        $this->assertNotNull($card);
        $this->assertSame($this->colId('aguardando'), (int) $card->column_id);
        $this->assertTrue(CardTransition::query()->where('card_id', $card->id)->where('cause', 'sem_resposta')->exists());
    }

    public function test_unmatched_modo_auto_sem_fluxo_valido_idem(): void
    {
        // Auto SEM fluxo padrao: degradacao graciosa (fatia 4) = silencio + unmatched.
        AutoReplySetting::withoutAccountScope()->where('account_id', $this->account->id)
            ->update(['operation_mode' => OperationMode::Auto, 'default_flow_id' => null]);

        $this->receber('ninguem me responde', 'K5');

        $this->assertSame(0, \App\Models\AutoReplyLog::withoutAccountScope()->count());
        $this->assertSame(1, UnmatchedMessage::withoutAccountScope()->where('account_id', $this->account->id)->count());
        $this->assertSame($this->colId('aguardando'), (int) $this->card()->column_id);
    }

    public function test_falha_do_kanban_no_move_de_unmatched_e_isolada(): void
    {
        // Kanban inteiro quebrado: moveToColumnSlug explode, apply vira no-op.
        $this->mock(BoardEngine::class, function ($mock) {
            $mock->shouldReceive('moveToColumnSlug')->andThrow(new \RuntimeException('kanban quebrado'));
            $mock->shouldReceive('apply');
        });

        $this->receber('sem match com kanban quebrado', 'K6');

        // Pipeline SEGUE: mensagem persistida e unmatched registrado (decisao intacta).
        $this->assertDatabaseHas('incoming_messages', ['evolution_message_id' => 'K6']);
        $this->assertSame(1, UnmatchedMessage::withoutAccountScope()->where('account_id', $this->account->id)->count());
    }

    public function test_isolamento_unmatched_em_a_nao_toca_board_de_b(): void
    {
        // Conta B com contato do MESMO jid e board proprio.
        $b = Account::create(['name' => 'B']);
        Contact::create(['account_id' => $b->id, 'remote_jid' => self::JID, 'auto_reply_mode' => 'on']);
        $boardB = Board::withoutAccountScope()->where('account_id', $b->id)->where('is_default', true)->first();

        $this->receber('sem match', 'K7'); // unmatched na conta A

        $this->assertNotNull($this->card()); // A ganhou card em aguardando
        $this->assertSame(0, Card::withoutAccountScope()->where('board_id', $boardB->id)->count()); // B intacto
    }

    public function test_idempotencia_mesmo_evento_sem_resposta_nao_duplica_transicao(): void
    {
        Contact::create(['account_id' => $this->account->id, 'remote_jid' => self::JID, 'auto_reply_mode' => 'on']);
        $engine = app(BoardEngine::class);

        // Re-entrega do MESMO evento (mesmo event_ref): uma transicao so.
        $engine->moveToColumnSlug('aguardando', $this->account->id, self::JID, 'sem_resposta', 42, cause: 'sem_resposta');
        $engine->moveToColumnSlug('aguardando', $this->account->id, self::JID, 'sem_resposta', 42, cause: 'sem_resposta');

        $card = $this->card();
        $this->assertSame($this->colId('aguardando'), (int) $card->column_id);
        $this->assertSame(1, CardTransition::query()->where('card_id', $card->id)->where('event_type', 'sem_resposta')->count());
    }
}
