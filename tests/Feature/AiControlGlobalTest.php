<?php

namespace Tests\Feature;

use App\Ai\AiClassification;
use App\Ai\AiClassificationRequest;
use App\Contracts\AiClassifier;
use App\Jobs\ClassifyWithAi;
use App\Jobs\ProcessIncomingWhatsappMessage;
use App\Kanban\BoardEngine;
use App\Livewire\Contatos;
use App\Models\Account;
use App\Models\AutoReplySetting;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Card;
use App\Models\CardTransition;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\IncomingMessage;
use App\Models\UnmatchedMessage;
use App\Tenancy\AccountContext;
use App\Whatsapp\AutoReply\AntiBanGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fatia 16 — IA consolidada no GLOBAL (cenario 2 do diagnostico): aiEligible
 * deixou de ler Contact.ai_enabled (coluna dormente; 0 contatos divergentes em
 * producao); o controle e o kill switch por conta. Mute (auto_reply_mode)
 * INTOCADO — segue vetando via contactGate. Parte C: IA sem resposta move o
 * card pra 'aguardando' (mesmo mecanismo best-effort/idempotente da Fatia 11).
 */
class AiControlGlobalTest extends TestCase
{
    use RefreshDatabase;

    private const JID = '5541966665555@s.whatsapp.net';

    private Account $account;
    private Channel $channel;

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
            'ai_enabled' => true, // GLOBAL ligado (o default do sistema segue OFF)
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Classificador que EXPLODE se consultado (o desfecho sem-candidatas nem chama IA). */
    private function classificadorNuncaChamado(): AiClassifier
    {
        return new class implements AiClassifier
        {
            public function classify(AiClassificationRequest $request): AiClassification
            {
                throw new \RuntimeException('IA nao deveria ter sido consultada neste cenario.');
            }

            public function answer(\App\Ai\AiAnswerRequest $request): \App\Ai\AiAnswer
            {
                throw new \RuntimeException('IA nao deveria ter sido consultada neste cenario.');
            }
        };
    }

    private function contato(string $mode = 'on', bool $aiFlag = false): Contact
    {
        return Contact::create([
            'account_id' => $this->account->id, 'remote_jid' => self::JID,
            'auto_reply_mode' => $mode, 'ai_enabled' => $aiFlag,
        ]);
    }

    private function incoming(string $texto, string $id = 'AI1'): IncomingMessage
    {
        return IncomingMessage::create([
            'account_id' => $this->account->id, 'channel_id' => $this->channel->id,
            'instance' => $this->channel->instance, 'evolution_message_id' => $id,
            'remote_jid' => self::JID, 'from_me' => false, 'type' => 'conversation',
            'text' => $texto, 'raw_payload' => ['x' => 1], 'received_at' => now(),
        ]);
    }

    private function receber(string $texto, string $id): void
    {
        (new ProcessIncomingWhatsappMessage([
            'event' => 'messages.upsert', 'instance' => 'inst-a',
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

    private function runJob(int $incomingId): void
    {
        (new ClassifyWithAi($incomingId))->handle(
            $this->classificadorNuncaChamado(),
            app(AntiBanGuard::class),
            app(\App\Whatsapp\AutoReply\RuleMatcher::class),
            app(\App\Whatsapp\Secrets\SecretVault::class),
            app(\App\Whatsapp\AutoReply\RuleResponder::class),
        );
    }

    // ---- Composicao GLOBAL do aiEligible (cenario 2) ---------------------------

    public function test_global_on_contato_sem_flag_individual_e_elegivel_novo_comportamento(): void
    {
        $this->contato(aiFlag: false); // flag por contato DESLIGADO (dormente)
        Queue::fake();

        $this->receber('mensagem sem regra nenhuma', 'G1');

        Queue::assertPushed(ClassifyWithAi::class); // antes: NUNCA despachava sem o flag
    }

    public function test_global_off_nunca_roda_mesmo_com_flag_individual_ligado(): void
    {
        AutoReplySetting::withoutAccountScope()->where('account_id', $this->account->id)->update(['ai_enabled' => false]);
        $this->contato(aiFlag: true); // flag individual LIGADO — dormente, ignorado
        Queue::fake();

        $this->receber('mensagem sem regra nenhuma', 'G2');

        Queue::assertNotPushed(ClassifyWithAi::class);
        // Cai no registro de sem-match do ingest (decisao de resposta preservada).
        $this->assertSame(1, UnmatchedMessage::withoutAccountScope()->where('account_id', $this->account->id)->count());
    }

    public function test_mute_continua_vetando_ia_mesmo_com_global_on(): void
    {
        $this->contato(mode: 'off'); // SILENCIADO (mute intocavel — handoff depende dele)
        Queue::fake();

        $this->receber('mensagem sem regra nenhuma', 'G3');

        Queue::assertNotPushed(ClassifyWithAi::class);
        $this->assertFalse(app(AntiBanGuard::class)->aiEligible($this->account->id, self::JID));
    }

    public function test_isolamento_global_da_conta_a_nao_afeta_b(): void
    {
        $this->contato();
        $b = Account::create(['name' => 'B']);
        AutoReplySetting::create(['account_id' => $b->id, 'reply_policy' => 'all', 'ai_enabled' => false]);
        Contact::create(['account_id' => $b->id, 'remote_jid' => self::JID, 'auto_reply_mode' => 'on']);

        $guard = app(AntiBanGuard::class);
        $this->assertTrue($guard->aiEligible($this->account->id, self::JID));  // A: global ON
        $this->assertFalse($guard->aiEligible($b->id, self::JID));             // B: global OFF
    }

    // ---- UI: campos dormentes (nao lidos, nao escritos, nao zerados) ------------

    public function test_ui_de_contato_nao_escreve_nem_zera_os_campos_dormentes(): void
    {
        $c = $this->contato(aiFlag: true); // valor antigo qualquer
        Contact::withoutAccountScope()->whereKey($c->id)->update(['saved' => true, 'ai_mode' => 'conhecimento']);

        Livewire::test(Contatos::class)
            ->call('startEdit', $c->id)
            ->set('editName', 'Novo Nome')
            ->call('saveEdit');

        // Colunas dormentes INTACTAS (nao zeradas — sem operacao destrutiva).
        $c->refresh();
        $this->assertTrue((bool) $c->ai_enabled);
        $this->assertSame('conhecimento', $c->ai_mode);
        $this->assertSame('Novo Nome', $c->push_name);

        // E a UI nao exibe mais controle de IA por contato.
        Livewire::test(Contatos::class)
            ->call('startEdit', $c->id)
            ->assertDontSee('IA para este contato');
    }

    // ---- Parte C: IA sem resposta -> card em 'aguardando' ------------------------

    private function colunaAguardando(): BoardColumn
    {
        $board = Board::withoutAccountScope()->where('account_id', $this->account->id)->where('is_default', true)->firstOrFail();

        return BoardColumn::query()->where('board_id', $board->id)->where('slug', 'aguardando')->firstOrFail();
    }

    public function test_ia_sem_resposta_move_card_para_aguardando_sem_mudar_decisao(): void
    {
        $this->contato(); // sem regras candidatas + modo default 'intencao' -> sem resposta
        $im = $this->incoming('duvida sem nenhuma regra');

        $this->runJob($im->id); // classificador explode se consultado: nem foi

        // Decisao IDENTICA: silencio + registro do sem-match.
        $this->assertSame(1, UnmatchedMessage::withoutAccountScope()->where('account_id', $this->account->id)->count());
        // Follow-up da Fatia 11: card em 'aguardando' com a causa propria.
        $card = Card::withoutAccountScope()->firstOrFail();
        $this->assertSame($this->colunaAguardando()->id, (int) $card->column_id);
        $this->assertDatabaseHas('card_transitions', [
            'card_id' => $card->id, 'cause' => 'sem_resposta', 'event_type' => 'sem_resposta', 'event_ref' => $im->id,
        ]);
    }

    public function test_idempotencia_com_o_move_do_ingest_mesmo_message_id_nao_duplica(): void
    {
        $this->contato();
        $im = $this->incoming('duvida sem regra', 'AI2');

        // Simula o ingest ja tendo movido por ESTA mensagem (mesmo event_ref).
        app(BoardEngine::class)->moveToColumnSlug('aguardando', $this->account->id, self::JID, 'sem_resposta', $im->id, cause: 'sem_resposta');
        $this->runJob($im->id);

        $card = Card::withoutAccountScope()->firstOrFail();
        $this->assertSame(1, CardTransition::query()
            ->where('card_id', $card->id)->where('event_type', 'sem_resposta')->where('event_ref', $im->id)->count());
    }

    public function test_falha_do_kanban_e_isolada_o_job_da_ia_segue(): void
    {
        $this->contato();
        $im = $this->incoming('duvida sem regra', 'AI3');
        $this->mock(BoardEngine::class, function ($mock) {
            $mock->shouldReceive('moveToColumnSlug')->andThrow(new \RuntimeException('kanban quebrado'));
            $mock->shouldReceive('apply');
        });

        $this->runJob($im->id); // NAO explode

        $this->assertSame(1, UnmatchedMessage::withoutAccountScope()->where('account_id', $this->account->id)->count());
    }
}
