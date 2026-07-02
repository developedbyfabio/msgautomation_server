<?php

namespace Tests\Feature;

use App\Jobs\SendProactiveMessage;
use App\Livewire\Campanhas;
use App\Livewire\Contatos;
use App\Models\Account;
use App\Models\AutoReplySetting;
use App\Models\CampaignTarget;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ProactiveCampaign;
use App\Whatsapp\AutoReply\Sender;
use App\Whatsapp\Proactive\ProactiveGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proativas P-3 — o disparo REAL, inteiro dentro da jaula. HTTP mockado (nunca
 * envio real; Http::assertNothingSent fora dos caminhos mockados). Tick so
 * enfileira; claim atomico por target e pelos tetos; Sender modo proactive com
 * R2 no instante do POST; teto pausa e retoma amanha; opt-out no meio pula o
 * contato em tudo; retry idempotente. TUDO segue OFF fora dos testes.
 */
class ProactiveDispatchTest extends TestCase
{
    use RefreshDatabase;

    private const JID = '5541999990000@s.whatsapp.net';
    private Account $account;
    private Channel $channel;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        // Quarta 10h SP (janela proativa default 09-18 aberta).
        Carbon::setTestNow(Carbon::create(2026, 7, 1, 10, 0, 0, 'America/Sao_Paulo'));
        Http::fake(['*' => Http::response(['key' => ['id' => 'PMID']], 201)]);

        $this->account = Account::create(['name' => 'T']);
        $this->channel = Channel::create(['account_id' => $this->account->id, 'instance' => 'fabio-pessoal', 'status' => 'connected']);
        AutoReplySetting::create([
            'account_id' => $this->account->id, 'enabled' => true, 'reply_policy' => 'all',
            'min_interval_seconds' => 0, 'per_minute_cap' => 100, 'per_day_cap' => 100,
            'contact_rate_seconds' => 0, 'contact_rate_enabled' => false,
            'delay_min_seconds' => 0, 'delay_max_seconds' => 0,
            'proactive_enabled' => true, // MOCK de teste — producao segue OFF
        ]);
        $this->contact = Contact::create([
            'account_id' => $this->account->id, 'remote_jid' => self::JID,
            'auto_reply_mode' => 'on', 'push_name' => 'Cliente', 'proactive_opt_in' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Campanha aprovada com 1 target vencido pro contato dado (ou o default). */
    private function aprovada(?Contact $contact = null, array $extra = []): array
    {
        $contact = $contact ?: $this->contact;
        $camp = ProactiveCampaign::create(array_merge([
            'account_id' => $this->account->id,
            'name' => 'Follow-up',
            'message' => '{saudacao}, {nome}! Ainda tem interesse?',
            'audience_type' => 'contatos',
            'audience_config' => ['contact_ids' => [$contact->id]],
            'status' => 'approved',
            'approved_at' => now(),
        ], $extra));
        $target = CampaignTarget::create([
            'campaign_id' => $camp->id, 'contact_id' => $contact->id,
            'status' => 'pending', 'scheduled_at' => now()->subMinute(),
        ]);

        return [$camp, $target];
    }

    private function job(CampaignTarget $target): SendProactiveMessage
    {
        return new SendProactiveMessage((int) $target->id, (int) $this->account->id);
    }

    private function runJob(CampaignTarget $target): void
    {
        $this->job($target)->handle(
            app(ProactiveGuard::class),
            app(Sender::class),
            app(\App\Whatsapp\AutoReply\RuleResponder::class),
            app(\App\Whatsapp\Proactive\AgendaBuilder::class),
        );
    }

    // ---- tick: so enfileira, so quem pode --------------------------------------

    public function test_tick_so_enfileira_contas_com_switch_on_e_campanhas_aprovadas(): void
    {
        Queue::fake();
        [$camp, $target] = $this->aprovada();

        // Conta B com switch OFF e campanha aprovada: NADA enfileirado pra ela.
        $b = Account::create(['name' => 'B']);
        AutoReplySetting::create(['account_id' => $b->id]); // proactive OFF (default)
        $contatoB = Contact::create(['account_id' => $b->id, 'remote_jid' => self::JID, 'proactive_opt_in' => true]);
        $campB = ProactiveCampaign::create(['account_id' => $b->id, 'name' => 'B', 'message' => 'oi', 'audience_type' => 'contatos', 'audience_config' => ['contact_ids' => [$contatoB->id]], 'status' => 'approved']);
        CampaignTarget::create(['campaign_id' => $campB->id, 'contact_id' => $contatoB->id, 'status' => 'pending', 'scheduled_at' => now()->subMinute()]);

        // Campanha em DRAFT na conta A com target vencido: fora.
        $draft = ProactiveCampaign::create(['account_id' => $this->account->id, 'name' => 'Draft', 'message' => 'oi', 'audience_type' => 'contatos', 'audience_config' => ['contact_ids' => [$this->contact->id]], 'status' => 'draft']);
        CampaignTarget::create(['campaign_id' => $draft->id, 'contact_id' => $this->contact->id, 'status' => 'pending', 'scheduled_at' => now()->subMinute()]);

        $this->artisan('proactive:tick')->assertSuccessful();

        Queue::assertPushed(SendProactiveMessage::class, 1);
        Queue::assertPushed(SendProactiveMessage::class, fn ($job) => $job->targetId === $target->id && $job->accountId === $this->account->id);
    }

    public function test_tick_respeita_o_lote(): void
    {
        Queue::fake();
        config(['proactive.tick_batch' => 2]);
        $camp = ProactiveCampaign::create(['account_id' => $this->account->id, 'name' => 'Lote', 'message' => 'oi', 'audience_type' => 'contatos', 'audience_config' => ['contact_ids' => []], 'status' => 'approved']);
        foreach (range(1, 5) as $i) {
            $c = Contact::create(['account_id' => $this->account->id, 'remote_jid' => "554188888000{$i}@s.whatsapp.net", 'auto_reply_mode' => 'on', 'proactive_opt_in' => true]);
            CampaignTarget::create(['campaign_id' => $camp->id, 'contact_id' => $c->id, 'status' => 'pending', 'scheduled_at' => now()->subMinute()]);
        }

        $this->artisan('proactive:tick')->assertSuccessful();

        Queue::assertPushed(SendProactiveMessage::class, 2); // nunca raja
    }

    public function test_tick_ignora_targets_futuros(): void
    {
        Queue::fake();
        [$camp, $target] = $this->aprovada();
        $target->update(['scheduled_at' => now()->addHour()]);

        $this->artisan('proactive:tick')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    // ---- caminho feliz -----------------------------------------------------------

    public function test_caminho_feliz_envia_e_loga_origem_proactive(): void
    {
        [$camp, $target] = $this->aprovada();

        $this->runJob($target);

        // Placeholders renderizados localmente (10h -> Bom dia; nome do contato).
        Http::assertSent(fn ($r) => $r['text'] === 'Bom dia, Cliente! Ainda tem interesse?');
        $target->refresh();
        $this->assertSame('sent', $target->status);
        $this->assertNotNull($target->sent_at);
        $this->assertDatabaseHas('auto_reply_logs', [
            'id' => $target->sent_auto_reply_log_id, 'mode' => 'proactive',
            'campaign_id' => $camp->id, 'status' => 'sent', 'remote_jid' => self::JID,
        ]);
        // Contadores consumidos + campanha concluida (unico target).
        $this->assertSame(1, app(ProactiveGuard::class)->dayCount($this->account->id));
        $this->assertSame('done', $camp->fresh()->status);
    }

    // ---- corrida / idempotencia -----------------------------------------------------

    public function test_corrida_no_mesmo_target_um_envia_outro_sai(): void
    {
        [$camp, $target] = $this->aprovada();

        $this->runJob($target); // claima e envia
        $this->runJob($target); // claim falha (nao esta mais pending) -> sai

        Http::assertSentCount(1); // ZERO duplicata
        $this->assertSame(1, CampaignTarget::where('campaign_id', $camp->id)->where('status', 'sent')->count());
    }

    // ---- bloqueios tratados por motivo ------------------------------------------------

    public function test_teto_diario_no_meio_reagenda_pro_dia_seguinte(): void
    {
        AutoReplySetting::withoutAccountScope()->where('account_id', $this->account->id)->update(['proactive_daily_cap' => 1]);
        $outro = Contact::create(['account_id' => $this->account->id, 'remote_jid' => '5541888880000@s.whatsapp.net', 'auto_reply_mode' => 'on', 'proactive_opt_in' => true, 'push_name' => 'Outro']);
        [$camp1, $t1] = $this->aprovada();
        [$camp2, $t2] = $this->aprovada($outro);

        $this->runJob($t1); // consome o teto do dia (1)
        $this->runJob($t2); // teto estourado -> reagenda amanha

        Http::assertSentCount(1);
        $t2->refresh();
        $this->assertSame('pending', $t2->status); // ninguem se perde
        $tz = config('app.display_timezone');
        $this->assertSame('2026-07-02', $t2->scheduled_at->copy()->setTimezone($tz)->format('Y-m-d'));
        $this->assertGreaterThanOrEqual('09:00:00', $t2->scheduled_at->copy()->setTimezone($tz)->format('H:i:s'));
    }

    public function test_kill_switch_off_no_meio_deixa_pending_aguardando(): void
    {
        [$camp, $target] = $this->aprovada();
        $agendadoOriginal = $target->scheduled_at;
        AutoReplySetting::withoutAccountScope()->where('account_id', $this->account->id)->update(['proactive_enabled' => false]);

        $this->runJob($target);

        Http::assertNothingSent();
        $target->refresh();
        $this->assertSame('pending', $target->status);
        // SEM novo scheduled_at: aguarda religar (o tick nao pega conta OFF).
        $this->assertSame($agendadoOriginal->timestamp, $target->scheduled_at->timestamp);
    }

    public function test_opt_out_do_robo_e_revogacao_pulam_definitivo(): void
    {
        $this->contact->update(['auto_reply_mode' => 'off']);
        [$camp, $target] = $this->aprovada();

        $this->runJob($target);

        Http::assertNothingSent();
        $this->assertSame('skipped', $target->fresh()->status);
        $this->assertSame('opt_out', $target->fresh()->skip_reason);
        $this->assertSame(0, app(ProactiveGuard::class)->dayCount($this->account->id)); // nada gasto
        $this->assertSame('done', $camp->fresh()->status); // nada restou
    }

    public function test_fora_da_janela_reagenda_pra_abertura(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 1, 20, 0, 0, 'America/Sao_Paulo')); // janela fechada
        [$camp, $target] = $this->aprovada();

        $this->runJob($target);

        Http::assertNothingSent();
        $target->refresh();
        $this->assertSame('pending', $target->status);
        $local = $target->scheduled_at->copy()->setTimezone(config('app.display_timezone'));
        $this->assertSame('2026-07-02 09:00:00', $local->format('Y-m-d H:i:s')); // abertura de amanha
    }

    public function test_r2_do_sender_segura_no_instante_do_post(): void
    {
        // R2 volatil DENTRO do Sender: opt-in revogado "entre o check e o POST"
        // (aqui: direto no Sender, que e a ultima linha antes do HTTP).
        $this->contact->update(['proactive_opt_in' => false]);

        $log = app(Sender::class)->send(
            mode: 'proactive', channel: $this->channel, jid: self::JID,
            text: 'oi', campaignId: null,
        );

        Http::assertNothingSent();
        $this->assertSame('blocked', $log->status);
        $this->assertSame('sem_opt_in', $log->motivo);
    }

    // ---- retry idempotente ---------------------------------------------------------------

    public function test_erro_transitorio_retenta_e_envia_uma_vez(): void
    {
        [$camp, $target] = $this->aprovada();

        // 1a tentativa: HTTP 500 -> devolve claim, target volta a pending, LANCA (fila retenta).
        // (swap: substitui a factory — fakes empilhados do setUp nao respondem mais)
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response(['error' => 'oops'], 500)]);
        try {
            $this->runJob($target);
            $this->fail('Deveria lancar pra fila retentar.');
        } catch (\RuntimeException) {
        }
        $target->refresh();
        $this->assertSame('pending', $target->status);
        $this->assertSame(0, app(ProactiveGuard::class)->dayCount($this->account->id)); // claim devolvido

        // 2a tentativa (retry): HTTP ok -> envia UMA vez.
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response(['key' => ['id' => 'PMID']], 201)]);
        $this->runJob($target);
        Http::assertSentCount(1);
        $this->assertSame('sent', $target->fresh()->status);
    }

    public function test_esgotou_retries_marca_failed_com_motivo(): void
    {
        [$camp, $target] = $this->aprovada();
        $target->update(['status' => 'pending']);

        $this->job($target)->failed(new \RuntimeException('esgotou'));

        $this->assertSame('failed', $target->fresh()->status);
        $this->assertSame('erro_envio', $target->fresh()->skip_reason);
    }

    // ---- estados: pausar/retomar/des-aprovar/opt-out no meio -------------------------------

    public function test_pausar_segura_e_retomar_reagenda_vencidos(): void
    {
        [$camp, $target] = $this->aprovada();

        Livewire::test(Campanhas::class)->call('askPause', $camp->id)->call('pauseConfirmed');
        $this->assertSame('paused', $camp->fresh()->status);

        // Tick nao pega campanha pausada.
        Queue::fake();
        $this->artisan('proactive:tick');
        Queue::assertNothingPushed();

        // Retomar: vencido reagendado pra frente, dentro da janela.
        Livewire::test(Campanhas::class)->call('resume', $camp->id);
        $camp->refresh();
        $target->refresh();
        $this->assertSame('running', $camp->status);
        $this->assertTrue($target->scheduled_at->gte(now()));
    }

    public function test_desaprovar_bloqueado_quando_ja_enviou(): void
    {
        [$camp, $target] = $this->aprovada();
        $this->runJob($target); // sent

        Livewire::test(Campanhas::class)
            ->call('askUnapprove', $camp->id)
            ->call('unapproveConfirmed');

        // Nao desfez: campanha segue (done, pois o unico target foi enviado) e o
        // target sent permanece registrado.
        $this->assertNotSame('draft', $camp->fresh()->status);
        $this->assertSame(1, CampaignTarget::where('campaign_id', $camp->id)->where('status', 'sent')->count());
    }

    public function test_opt_out_por_palavra_no_meio_pula_o_contato_em_todas_as_campanhas(): void
    {
        [$camp1, $t1] = $this->aprovada();
        [$camp2, $t2] = $this->aprovada(); // segunda campanha, mesmo contato

        // Palavra de opt-out chega pelo pipeline reativo.
        (new \App\Jobs\ProcessIncomingWhatsappMessage([
            'event' => 'messages.upsert', 'instance' => 'fabio-pessoal',
            'data' => [
                'key' => ['id' => 'OPT1', 'fromMe' => false, 'remoteJid' => self::JID],
                'pushName' => 'Cliente', 'messageType' => 'conversation',
                'message' => ['conversation' => 'PARAR'], 'messageTimestamp' => 1782699162,
            ],
        ]))->handle(
            app(\App\Contracts\WhatsappGateway::class),
            app(\App\Whatsapp\AutoReply\RuleMatcher::class),
            app(\App\Whatsapp\AutoReply\AntiBanGuard::class),
        );

        $this->assertSame('skipped', $t1->fresh()->status);
        $this->assertSame('skipped', $t2->fresh()->status);
        $this->assertSame('opt_out_revogado', $t1->fresh()->skip_reason);
        Http::assertNothingSent();

        // Revogacao MANUAL no painel tem o mesmo efeito. (update via query: a
        // instancia do setUp ainda acha que opt_in=true — dirty check pularia o SQL)
        Contact::withoutAccountScope()->whereKey($this->contact->id)->update(['proactive_opt_in' => true]);
        [$camp3, $t3] = $this->aprovada();
        Livewire::test(Contatos::class)
            ->call('startEdit', $this->contact->id)
            ->set('editProactiveOptIn', false)
            ->call('saveEdit');
        $this->assertSame('skipped', $t3->fresh()->status);
    }
}
