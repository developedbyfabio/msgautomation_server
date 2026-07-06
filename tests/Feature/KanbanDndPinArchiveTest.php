<?php

namespace Tests\Feature;

use App\Enums\OperationMode;
use App\Jobs\ProcessIncomingWhatsappMessage;
use App\Kanban\BoardEngine;
use App\Livewire\Kanban;
use App\Models\Account;
use App\Models\AutoReplyRule;
use App\Models\AutoReplySetting;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Card;
use App\Models\CardTransition;
use App\Models\Channel;
use App\Models\Contact;
use App\Tenancy\AccountContext;
use App\Whatsapp\AutoReply\Sender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fatia 20 — Kanban: (A) drag reusa a MESMA action moveCard dos 3 pontinhos;
 * (B) movimento HUMANO fixa o card (pinned_until_reply) e as transicoes
 * automaticas respeitam ate a PROXIMA mensagem do contato (release no inbound,
 * que tambem DESARQUIVA); (C) "arquivar parados": so inativos > X dias,
 * reversivel (archived_at), NUNCA delete fisico, NUNCA a coluna inteira.
 */
class KanbanDndPinArchiveTest extends TestCase
{
    use RefreshDatabase;

    private const JID = '5541955554444@s.whatsapp.net';

    private Account $account;
    private Channel $channel;
    private Board $board;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 7, 6, 10, 0, 0, 'America/Sao_Paulo'));
        Http::fake(['*' => Http::response(['key' => ['id' => 'PMID']], 201)]);

        $this->account = Account::create(['name' => 'A']);
        app(AccountContext::class)->set($this->account->id);
        $this->channel = Channel::create(['account_id' => $this->account->id, 'instance' => 'inst-a', 'status' => 'connected']);
        AutoReplySetting::create([
            'account_id' => $this->account->id, 'enabled' => true, 'reply_policy' => 'all',
            'window_start' => '08:00:00', 'window_end' => '20:00:00',
            'min_interval_seconds' => 0, 'per_minute_cap' => 100, 'per_day_cap' => 100,
            'contact_rate_seconds' => 0, 'delay_min_seconds' => 0, 'delay_max_seconds' => 0,
        ]);
        $this->board = Board::withoutAccountScope()->where('account_id', $this->account->id)->where('is_default', true)->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function col(string $slug): BoardColumn
    {
        return $this->board->columns()->where('slug', $slug)->firstOrFail();
    }

    private function receber(string $texto, string $id): void
    {
        (new ProcessIncomingWhatsappMessage([
            'event' => 'messages.upsert', 'instance' => 'inst-a',
            'data' => [
                'key' => ['id' => $id, 'fromMe' => false, 'remoteJid' => self::JID],
                'pushName' => 'Cliente Kanban', 'messageType' => 'conversation',
                'message' => ['conversation' => $texto], 'messageTimestamp' => 1782699162,
            ],
        ]))->handle(
            app(\App\Contracts\WhatsappGateway::class),
            app(\App\Whatsapp\AutoReply\RuleMatcher::class),
            app(\App\Whatsapp\AutoReply\AntiBanGuard::class),
        );
    }

    /** Contato + card em uma coluna (direto — estado de partida dos cenarios). */
    private function cardEm(string $slug, string $jid = self::JID, ?int $accountId = null): Card
    {
        $aid = $accountId ?? $this->account->id;
        $board = Board::withoutAccountScope()->where('account_id', $aid)->where('is_default', true)->firstOrFail();
        $contact = Contact::withoutAccountScope()->firstOrCreate(
            ['account_id' => $aid, 'remote_jid' => $jid],
            ['auto_reply_mode' => 'on', 'push_name' => 'Cliente Kanban'],
        );

        return Card::create([
            'account_id' => $aid, 'board_id' => $board->id, 'contact_id' => $contact->id,
            'column_id' => $board->columns()->where('slug', $slug)->firstOrFail()->id,
            'last_interaction_at' => now(),
        ]);
    }

    // ---- A: mover (3 pontinhos e drag usam a MESMA action) -----------------------

    public function test_move_humano_persiste_e_fixa_o_card(): void
    {
        $card = $this->cardEm('novo');

        Livewire::test(Kanban::class)->call('moveCard', $card->id, $this->col('resolvido')->id);

        $card->refresh();
        $this->assertSame($this->col('resolvido')->id, (int) $card->column_id);
        $this->assertTrue($card->pinned_until_reply); // FIXADO pelo movimento humano
        $this->assertDatabaseHas('card_transitions', ['card_id' => $card->id, 'cause' => 'manual']);
    }

    public function test_posse_mover_card_de_outra_conta_e_noop(): void
    {
        $b = Account::create(['name' => 'B']);
        $cardB = $this->cardEm('novo', '5541900001111@s.whatsapp.net', $b->id);

        // Contexto = conta A: o card da B nao existe no board de A -> no-op.
        Livewire::test(Kanban::class)->call('moveCard', $cardB->id, $this->col('resolvido')->id);

        $cardB->refresh();
        $this->assertFalse($cardB->pinned_until_reply);
        $this->assertSame('novo', BoardColumn::query()->whereKey($cardB->column_id)->value('slug'));
    }

    // ---- B: o par critico pin/release ---------------------------------------------

    public function test_card_fixado_nao_e_movido_pela_transicao_automatica(): void
    {
        $card = $this->cardEm('novo');
        Livewire::test(Kanban::class)->call('moveCard', $card->id, $this->col('resolvido')->id); // humano fixa

        // Robo envia SEM inbound novo (aprovacao do /revisao): resposta_enviada
        // moveria pra em_atendimento — mas o card esta FIXADO.
        app(Sender::class)->send('aprovacao', $this->channel, self::JID, 'resposta aprovada');

        $this->assertSame($this->col('resolvido')->id, (int) $card->fresh()->column_id); // ficou onde o humano pos

        // O move DETERMINISTICO (handoff/sem_resposta) tambem respeita o pin.
        app(BoardEngine::class)->moveToColumnSlug('aguardando', $this->account->id, self::JID, 'sem_resposta', 999, cause: 'sem_resposta');
        $this->assertSame($this->col('resolvido')->id, (int) $card->fresh()->column_id);
        $this->assertSame(0, CardTransition::query()->where('card_id', $card->id)->where('event_ref', 999)->count());
    }

    public function test_release_mensagem_do_contato_solta_o_pin_e_o_fluxo_reassume(): void
    {
        $card = $this->cardEm('novo');
        Livewire::test(Kanban::class)->call('moveCard', $card->id, $this->col('resolvido')->id); // fixado em resolvido

        // PROXIMA mensagem do contato: solta o pin E a propria mensagem aciona as
        // transicoes normais em cadeia — reabre (resolvido -> novo, regra default)
        // e, sem regra que case, o sem-resposta da Fatia 11 leva pra 'aguardando'.
        $this->receber('voltei, preciso de ajuda', 'P1');

        $card->refresh();
        $this->assertFalse($card->pinned_until_reply); // release
        $this->assertDatabaseHas('card_transitions', [ // reabertura ACONTECEU (pin nao segurou)
            'card_id' => $card->id, 'from_column_id' => $this->col('resolvido')->id, 'to_column_id' => $this->col('novo')->id,
        ]);
        $this->assertSame($this->col('aguardando')->id, (int) $card->column_id); // fluxo reassumiu ate o fim
    }

    public function test_transicao_automatica_nao_seta_pin(): void
    {
        $this->cardEm('novo');

        // Robo responde (regra) -> resposta_enviada move pra em_atendimento.
        $rule = AutoReplyRule::create([
            'account_id' => $this->account->id, 'match_type' => 'contains', 'match_value' => 'preco',
            'response_text' => 'Tabela.', 'enabled' => true, 'priority' => 0,
        ]);
        $rule->triggers()->create(['match_type' => 'contains', 'match_value' => 'preco']);
        $rule->responses()->create(['response_text' => 'Tabela.']);
        $this->receber('qual o preco?', 'P2');

        $card = Card::withoutAccountScope()->where('board_id', $this->board->id)->firstOrFail();
        $this->assertSame($this->col('em_atendimento')->id, (int) $card->column_id); // regressao: automatico segue
        $this->assertFalse($card->pinned_until_reply);                                // e NAO fixa
    }

    // ---- C: arquivar parados (reversivel, escopado) --------------------------------

    public function test_arquiva_so_os_inativos_ha_mais_de_x_dias(): void
    {
        $antigo = $this->cardEm('novo');
        Card::withoutAccountScope()->whereKey($antigo->id)->update(['last_interaction_at' => now()->subDays(40)]);
        $recente = $this->cardEm('novo', '5541900002222@s.whatsapp.net');

        $tela = Livewire::test(Kanban::class)
            ->call('confirmArchive', $this->col('novo')->id)
            ->assertSee('1 card(s)')            // contagem correta no dialogo
            ->assertSee('Cliente Kanban');      // e a lista de quem sera arquivado

        $tela->call('archiveConfirmed');

        // SO o parado foi arquivado; o recente PERMANECE (coluna nao e esvaziada).
        $this->assertNotNull($antigo->fresh()->archived_at);
        $this->assertNull($recente->fresh()->archived_at);
        // NUNCA fisico: a linha continua existindo.
        $this->assertDatabaseHas('cards', ['id' => $antigo->id]);
        // Arquivado some do board renderizado; o recente continua.
        Livewire::test(Kanban::class)->assertSee('5541900002222');
        $this->assertSame(1, Card::withoutAccountScope()->where('board_id', $this->board->id)->whereNull('archived_at')->count());
    }

    public function test_zero_elegiveis_e_noop_e_x_e_configuravel(): void
    {
        $card = $this->cardEm('novo');
        Card::withoutAccountScope()->whereKey($card->id)->update(['last_interaction_at' => now()->subDays(10)]);

        // Default 30 dias: 10 dias parado NAO e elegivel -> no-op.
        Livewire::test(Kanban::class)
            ->call('confirmArchive', $this->col('novo')->id)
            ->assertSee('nada sera arquivado')
            ->call('archiveConfirmed');
        $this->assertNull($card->fresh()->archived_at);

        // X configuravel: baixando pra 5 dias, o mesmo card vira elegivel.
        Livewire::test(Kanban::class)
            ->call('confirmArchive', $this->col('novo')->id)
            ->set('archiveDays', 5)
            ->assertSee('1 card(s)')
            ->call('archiveConfirmed');
        $this->assertNotNull($card->fresh()->archived_at);
    }

    public function test_arquivado_desarquiva_no_inbound_do_contato(): void
    {
        $card = $this->cardEm('novo');
        Card::withoutAccountScope()->whereKey($card->id)->update(['archived_at' => now(), 'last_interaction_at' => now()->subDays(60)]);

        $this->receber('oi, ainda existo!', 'P3');

        $this->assertNull($card->fresh()->archived_at); // AUTO-restaurado, zero perda
    }

    public function test_posse_arquivar_coluna_de_outra_conta_e_noop(): void
    {
        $b = Account::create(['name' => 'B']);
        $cardB = $this->cardEm('novo', '5541900003333@s.whatsapp.net', $b->id);
        Card::withoutAccountScope()->whereKey($cardB->id)->update(['last_interaction_at' => now()->subDays(90)]);
        $boardB = Board::withoutAccountScope()->where('account_id', $b->id)->where('is_default', true)->firstOrFail();
        $colB = $boardB->columns()->where('slug', 'novo')->firstOrFail();

        // Contexto = conta A: coluna da B nao pertence ao board de A -> modal nem abre.
        Livewire::test(Kanban::class)
            ->call('confirmArchive', $colB->id)
            ->assertSet('archivingColumnId', null);

        $this->assertNull($cardB->fresh()->archived_at); // B intacta
    }
}
