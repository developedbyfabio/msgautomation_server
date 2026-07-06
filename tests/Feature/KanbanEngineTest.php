<?php

namespace Tests\Feature;

use App\Events\AiDecisionRecorded;
use App\Events\FlowNodeReached;
use App\Events\IncomingMessageStored;
use App\Jobs\ProcessIncomingWhatsappMessage;
use App\Kanban\BoardEngine;
use App\Models\Account;
use App\Models\AutoReplyRule;
use App\Models\AutoReplySetting;
use App\Models\Board;
use App\Models\BoardRule;
use App\Models\Card;
use App\Models\CardTransition;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Whatsapp\AutoReply\Sender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Kanban K-1 — motor headless (OBSERVADOR PURO). HTTP mockado (nunca envio real).
 * Provas: card por evento com transicao/causa, reabertura, first-match, grupos
 * fora, idempotencia de re-entrega, falha do listener ISOLADA (pipeline intacto),
 * unique por contato+board.
 */
class KanbanEngineTest extends TestCase
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

        // Account::created provisiona o board default (colunas D4 + regras minimas).
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
                'pushName' => 'Cliente', 'messageType' => 'conversation',
                'message' => ['conversation' => $texto], 'messageTimestamp' => 1782699162,
            ],
        ]))->handle(
            app(\App\Contracts\WhatsappGateway::class),
            app(\App\Whatsapp\AutoReply\RuleMatcher::class),
            app(\App\Whatsapp\AutoReply\AntiBanGuard::class),
        );
    }

    private function col(string $slug): int
    {
        return (int) $this->board->columns()->where('slug', $slug)->value('id');
    }

    private function card(): ?Card
    {
        return Card::withoutAccountScope()->where('board_id', $this->board->id)->first();
    }

    // ---- criacao / reabertura ---------------------------------------------------

    public function test_primeira_mensagem_cria_card_em_novo_com_transicao(): void
    {
        $this->receber('oi, tudo bem?');

        $card = $this->card();
        $this->assertNotNull($card);
        // Fatia 11: mensagem SEM resposta do robo termina em 'aguardando'
        // (pendencia humana). A criacao em Novo pela regra segue provada abaixo,
        // na PRIMEIRA transicao.
        $this->assertSame($this->col('aguardando'), (int) $card->column_id);
        $this->assertSame('in', $card->last_direction);
        $this->assertNotNull($card->last_interaction_at);

        // 1a transicao: criacao pela REGRA default, com CAUSA completa (intacta).
        $t = CardTransition::where('card_id', $card->id)->orderBy('id')->first();
        $this->assertNotNull($t);
        $this->assertNull($t->from_column_id); // criado
        $this->assertSame($this->col('novo'), (int) $t->to_column_id);
        $this->assertSame('regra', $t->cause);
        $this->assertSame('mensagem_recebida', $t->event_type);
        $this->assertNotNull($t->board_rule_id);
        $this->assertDatabaseHas('incoming_messages', ['id' => $t->event_ref]);

        // 2a transicao (Fatia 11): novo -> aguardando por 'sem_resposta'.
        $this->assertDatabaseHas('card_transitions', [
            'card_id' => $card->id, 'cause' => 'sem_resposta', 'to_column_id' => $this->col('aguardando'),
        ]);
    }

    public function test_mensagem_em_card_resolvido_reabre_pra_novo(): void
    {
        $this->receber('oi', 'W1');
        $card = $this->card();
        Card::withoutAccountScope()->where('id', $card->id)->update(['column_id' => $this->col('resolvido')]);

        $this->receber('voltei com outra duvida', 'W2');

        // A REABERTURA (resolvido -> novo, pela regra default) segue acontecendo:
        $this->assertDatabaseHas('card_transitions', [
            'card_id' => $card->id, 'from_column_id' => $this->col('resolvido'),
            'to_column_id' => $this->col('novo'), 'cause' => 'regra',
        ]);
        // ...e a Fatia 11 leva adiante: mensagem reaberta SEM resposta do robo
        // termina como pendencia humana em 'aguardando'.
        $this->assertSame($this->col('aguardando'), (int) $card->fresh()->column_id);
    }

    public function test_mensagem_em_outras_colunas_so_atualiza_interacao(): void
    {
        $this->receber('oi', 'W1');
        $card = $this->card();
        Card::withoutAccountScope()->where('id', $card->id)
            ->update(['column_id' => $this->col('aguardando'), 'last_interaction_at' => now()->subHour(), 'last_direction' => 'out']);
        $transicoes = CardTransition::where('card_id', $card->id)->count();

        $this->receber('mais uma mensagem', 'W2');

        $card->refresh();
        $this->assertSame($this->col('aguardando'), (int) $card->column_id); // nao moveu
        $this->assertSame('in', $card->last_direction);
        $this->assertTrue($card->last_interaction_at->gt(now()->subMinute()));
        $this->assertSame($transicoes, CardTransition::where('card_id', $card->id)->count()); // sem transicao nova
    }

    // ---- respostas movem pra Em atendimento ---------------------------------------

    public function test_resposta_automatica_move_pra_em_atendimento(): void
    {
        $rule = AutoReplyRule::create(['account_id' => $this->account->id, 'match_type' => 'contains', 'match_value' => 'horario', 'response_text' => 'Das 8h as 18h.', 'enabled' => true]);
        $rule->triggers()->create(['match_type' => 'contains', 'match_value' => 'horario']);
        $rule->responses()->create(['response_text' => 'Das 8h as 18h.']);
        Contact::create(['account_id' => $this->account->id, 'remote_jid' => self::JID, 'auto_reply_mode' => 'on']);

        $this->receber('qual o horario?');

        Http::assertSentCount(1); // resposta saiu normalmente (observador nao interfere)
        $card = $this->card();
        $this->assertSame($this->col('em_atendimento'), (int) $card->column_id);
        $this->assertSame('out', $card->last_direction);
        $this->assertDatabaseHas('card_transitions', [
            'card_id' => $card->id, 'event_type' => 'resposta_enviada', 'cause' => 'regra',
        ]);
    }

    public function test_envio_manual_move_pra_em_atendimento_sem_duplicar_quando_ja_esta(): void
    {
        Contact::create(['account_id' => $this->account->id, 'remote_jid' => self::JID, 'auto_reply_mode' => 'on']);
        $this->receber('oi', 'W1'); // card em Novo

        app(Sender::class)->send('manual', $this->channel, self::JID, 'ola, aqui e o Fabio');
        $card = $this->card();
        $this->assertSame($this->col('em_atendimento'), (int) $card->column_id);
        $antes = CardTransition::where('card_id', $card->id)->count();

        // Segundo envio manual: card JA esta em Em atendimento -> sem transicao nova.
        app(Sender::class)->send('manual', $this->channel, self::JID, 'complemento');
        $this->assertSame($antes, CardTransition::where('card_id', $card->id)->count());
        $this->assertSame($this->col('em_atendimento'), (int) $card->fresh()->column_id);
    }

    public function test_envio_aprovado_no_revisao_tambem_move(): void
    {
        Contact::create(['account_id' => $this->account->id, 'remote_jid' => self::JID, 'auto_reply_mode' => 'on']);
        $this->receber('oi', 'W1');

        // Modo 'aprovacao' (Fatia 3) emite AutoReplySent -> regra default move.
        app(Sender::class)->send('aprovacao', $this->channel, self::JID, 'resposta aprovada');

        $this->assertSame($this->col('em_atendimento'), (int) $this->card()->fresh()->column_id);
    }

    // ---- grupos / idempotencia / falha isolada --------------------------------------

    public function test_grupo_nao_cria_card(): void
    {
        $this->receber('oi grupo', 'G1', '123456789@g.us');

        $this->assertDatabaseCount('cards', 0);
        $this->assertDatabaseHas('incoming_messages', ['evolution_message_id' => 'G1']); // msg registrada normal
    }

    public function test_reentrega_do_mesmo_evento_nao_duplica_card_nem_transicao(): void
    {
        $this->receber('oi', 'W1');
        $im = \App\Models\IncomingMessage::withoutAccountScope()->where('evolution_message_id', 'W1')->first();
        $contato = Contact::withoutAccountScope()->where('account_id', $this->account->id)->where('remote_jid', self::JID)->first();

        // Re-entrega do MESMO evento (retry de listener/fila).
        event(new IncomingMessageStored((int) $this->account->id, (int) $im->id, (int) $contato->id, self::JID));
        event(new IncomingMessageStored((int) $this->account->id, (int) $im->id, (int) $contato->id, self::JID));

        $this->assertSame(1, Card::withoutAccountScope()->count());
        // Fatia 11: o processamento original ja gera DUAS transicoes (regra -> novo
        // + sem_resposta -> aguardando); a re-entrega nao duplica NENHUMA delas.
        $this->assertSame(1, CardTransition::where('event_type', 'mensagem_recebida')->count());
        $this->assertSame(1, CardTransition::where('event_type', 'sem_resposta')->count());
        $this->assertSame(2, CardTransition::count());
    }

    public function test_falha_no_listener_nao_derruba_o_pipeline(): void
    {
        // Engine quebrado injetado: o listener captura, loga e o pipeline segue.
        $this->app->instance(BoardEngine::class, new class(app(\App\Tenancy\AccountContext::class)) extends BoardEngine
        {
            public function apply(string $eventType, int $accountId, string $remoteJid, int $eventRef, ?string $direction = null, array $meta = []): void
            {
                throw new \RuntimeException('kanban quebrado de proposito');
            }
        });
        Log::spy();

        $rule = AutoReplyRule::create(['account_id' => $this->account->id, 'match_type' => 'contains', 'match_value' => 'horario', 'response_text' => 'Das 8h as 18h.', 'enabled' => true]);
        $rule->triggers()->create(['match_type' => 'contains', 'match_value' => 'horario']);
        $rule->responses()->create(['response_text' => 'Das 8h as 18h.']);
        Contact::create(['account_id' => $this->account->id, 'remote_jid' => self::JID, 'auto_reply_mode' => 'on']);

        $this->receber('qual o horario?');

        // Pipeline INTACTO: mensagem persistida E resposta enviada.
        $this->assertDatabaseHas('incoming_messages', ['evolution_message_id' => 'W1']);
        Http::assertSentCount(1);
        // Kanban nao mexeu em nada e o erro foi logado (isolado).
        $this->assertDatabaseCount('cards', 0);
        Log::shouldHaveReceived('error')->atLeast()->once();
    }

    public function test_unique_de_card_por_contato_e_board(): void
    {
        $contato = Contact::create(['account_id' => $this->account->id, 'remote_jid' => self::JID]);
        Card::create(['account_id' => $this->account->id, 'board_id' => $this->board->id, 'contact_id' => $contato->id, 'column_id' => $this->col('novo')]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        Card::create(['account_id' => $this->account->id, 'board_id' => $this->board->id, 'contact_id' => $contato->id, 'column_id' => $this->col('novo')]);
    }

    // ---- regras: ordem / inativa -----------------------------------------------------

    public function test_first_match_respeita_ordem_e_regra_inativa_e_ignorada(): void
    {
        // Regra customizada ANTES das defaults: primeira mensagem vai pra Aguardando.
        BoardRule::create([
            'account_id' => $this->account->id, 'board_id' => $this->board->id,
            'event_type' => 'mensagem_recebida', 'conditions' => ['card' => 'absent'],
            'to_column_id' => $this->col('aguardando'), 'active' => true, 'position' => -1,
        ]);

        $this->receber('oi', 'W1');
        $this->assertSame($this->col('aguardando'), (int) $this->card()->column_id);

        // Desativada, a default (Novo) volta a valer pra um contato novo.
        BoardRule::withoutAccountScope()->where('to_column_id', $this->col('aguardando'))->update(['active' => false]);
        $this->receber('oi', 'W2', '5541888880000@s.whatsapp.net');

        $novo = Card::withoutAccountScope()->where('board_id', $this->board->id)->latest('id')->first();
        // A regra default CRIOU o card em Novo (regra inativa ignorada; first-match
        // provado pela transicao)...
        $this->assertDatabaseHas('card_transitions', [
            'card_id' => $novo->id, 'from_column_id' => null,
            'to_column_id' => $this->col('novo'), 'cause' => 'regra',
        ]);
        // ...e a Fatia 11 seguiu com a mensagem sem resposta pra 'aguardando'.
        $this->assertSame($this->col('aguardando'), (int) $novo->column_id);
    }

    // ---- eventos informativos emitidos (sem regra default) -----------------------------

    public function test_fluxo_e_ia_emitem_eventos_de_dominio(): void
    {
        Event::fake([FlowNodeReached::class, AiDecisionRecorded::class]);

        $flow = Flow::create(['account_id' => $this->account->id, 'name' => 'F', 'enabled' => true, 'timeout_seconds' => 600]);
        $flow->triggers()->create(['match_type' => 'contains', 'match_value' => 'menu']);
        $root = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'menu', 'message' => 'RAIZ: 1 - Suporte']);
        $fim = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'final', 'message' => 'FINAL']);
        $root->options()->create(['input' => '1', 'label' => '1 - Suporte', 'next_node_id' => $fim->id]);
        $flow->update(['root_node_id' => $root->id]);
        Contact::create(['account_id' => $this->account->id, 'remote_jid' => self::JID, 'auto_reply_mode' => 'on']);

        $this->receber('menu', 'F1');

        Event::assertDispatched(FlowNodeReached::class, fn ($e) => $e->accountId === (int) $this->account->id && $e->nodeId === (int) $root->id);
    }
}
