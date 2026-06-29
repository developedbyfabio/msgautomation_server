<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AutoReplyLog;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\IncomingMessage;
use App\Whatsapp\AutoReply\AntiBanGuard;
use App\Whatsapp\AutoReply\GuardDecision;
use App\Whatsapp\AutoReply\Sender;
use App\Whatsapp\AutoReply\Throttle;
use App\Whatsapp\Drivers\EvolutionDriver;
use App\Whatsapp\Exceptions\WhatsappSendException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Camada 2 Fatia 2: base de envio + freios. NADA de envio real (HTTP mockado).
 */
class AutoReplySendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Horario dentro da janela default (08-20) America/Sao_Paulo.
        Carbon::setTestNow(Carbon::create(2026, 6, 29, 10, 0, 0, 'America/Sao_Paulo'));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function scaffold(array $settings = []): array
    {
        $account = Account::create(['name' => 'Teste']);
        $channel = Channel::create([
            'account_id' => $account->id,
            'instance' => 'fabio-pessoal',
            'status' => 'connected',
        ]);
        AutoReplySetting::create(array_merge([
            'account_id' => $account->id,
            'enabled' => true,
            // Estes testes focam nos OUTROS freios; politica 'all' evita o portao de
            // allowlist barrar tudo. O comportamento do portao tem testes proprios.
            'reply_policy' => 'all',
            'window_start' => '08:00:00',
            'window_end' => '20:00:00',
            'min_interval_seconds' => 30,
            'per_minute_cap' => 4,
            'per_day_cap' => 40,
            'contact_rate_seconds' => 1800,
            'skip_groups' => true,
            'warmup_enabled' => false,
            'delay_min_seconds' => 3,
            'delay_max_seconds' => 15,
        ], $settings));

        return [$account, $channel];
    }

    private function incoming(Account $account, Channel $channel, string $jid, bool $fromMe = false): IncomingMessage
    {
        return IncomingMessage::create([
            'account_id' => $account->id,
            'channel_id' => $channel->id,
            'instance' => $channel->instance,
            'evolution_message_id' => 'EVO' . uniqid(),
            'remote_jid' => $jid,
            'from_me' => $fromMe,
            'type' => 'conversation',
            'text' => 'oi',
            'raw_payload' => ['x' => 1],
            'received_at' => now(),
        ]);
    }

    private function fakeOk(): void
    {
        Http::fake(['*' => Http::response(['key' => ['id' => 'PROVIDERMSG123']], 201)]);
    }

    private function sender(): Sender
    {
        return app(Sender::class);
    }

    // ---- Driver -------------------------------------------------------------

    public function test_driver_send_text_sucesso(): void
    {
        $this->fakeOk();
        $sent = app(EvolutionDriver::class)->sendText('fabio-pessoal', '5541999990000@s.whatsapp.net', 'ola');
        $this->assertSame('PROVIDERMSG123', $sent->providerMessageId);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/message/sendText/fabio-pessoal')
            && $r['number'] === '5541999990000' && $r['text'] === 'ola');
    }

    public function test_driver_send_text_falha_lanca_excecao(): void
    {
        Http::fake(['*' => Http::response(['error' => 'x'], 500)]);
        $this->expectException(WhatsappSendException::class);
        app(EvolutionDriver::class)->sendText('fabio-pessoal', '5541999990000@s.whatsapp.net', 'ola');
    }

    // ---- R1: manual ignora kill switch, respeita tetos ----------------------

    public function test_manual_envia_mesmo_com_kill_switch_off(): void
    {
        $this->fakeOk();
        [$account, $channel] = $this->scaffold(['enabled' => false]); // kill switch OFF

        $log = $this->sender()->send('manual', $channel, '5541999990000@s.whatsapp.net', 'manual');

        $this->assertSame('sent', $log->status);
        Http::assertSentCount(1);
    }

    public function test_manual_respeita_teto_dia(): void
    {
        $this->fakeOk();
        [$account, $channel] = $this->scaffold(['min_interval_seconds' => 0, 'per_day_cap' => 1]);

        $a = $this->sender()->send('manual', $channel, '5541999990000@s.whatsapp.net', 'um');
        $b = $this->sender()->send('manual', $channel, '5541999990000@s.whatsapp.net', 'dois');

        $this->assertSame('sent', $a->status);
        $this->assertSame('blocked', $b->status);
        $this->assertSame('teto_dia', $b->motivo);
        Http::assertSentCount(1);
    }

    public function test_manual_respeita_intervalo_minimo(): void
    {
        $this->fakeOk();
        [$account, $channel] = $this->scaffold(['min_interval_seconds' => 30]);

        $this->sender()->send('manual', $channel, '5541999990000@s.whatsapp.net', 'um');
        $b = $this->sender()->send('manual', $channel, '5541999990000@s.whatsapp.net', 'dois');

        $this->assertSame('blocked', $b->status);
        $this->assertSame('intervalo_minimo', $b->motivo);
        Http::assertSentCount(1);
    }

    // ---- Auto: freios -------------------------------------------------------

    public function test_auto_bloqueado_por_kill_switch(): void
    {
        $this->fakeOk();
        [$account, $channel] = $this->scaffold(['enabled' => false]);
        $im = $this->incoming($account, $channel, '5541999990000@s.whatsapp.net');

        $log = $this->sender()->send('auto', $channel, $im->remote_jid, 'resp', $im->id, null, false);

        $this->assertSame('blocked', $log->status);
        $this->assertSame('kill_switch', $log->motivo);
        Http::assertSentCount(0);
    }

    public function test_auto_guarda_from_me(): void
    {
        $this->fakeOk();
        [$account, $channel] = $this->scaffold();
        $im = $this->incoming($account, $channel, '5541999990000@s.whatsapp.net', fromMe: true);

        $log = $this->sender()->send('auto', $channel, $im->remote_jid, 'resp', $im->id, null, true);

        $this->assertSame('blocked', $log->status);
        $this->assertSame('from_me', $log->motivo);
        Http::assertSentCount(0);
    }

    public function test_auto_pula_grupo(): void
    {
        $this->fakeOk();
        [$account, $channel] = $this->scaffold();
        $im = $this->incoming($account, $channel, '123456789@g.us');

        $log = $this->sender()->send('auto', $channel, $im->remote_jid, 'resp', $im->id);

        $this->assertSame('blocked', $log->status);
        $this->assertSame('grupo', $log->motivo);
        Http::assertSentCount(0);
    }

    public function test_auto_respeita_opt_out(): void
    {
        $this->fakeOk();
        [$account, $channel] = $this->scaffold();
        Contact::create([
            'account_id' => $account->id,
            'remote_jid' => '5541999990000@s.whatsapp.net',
            'auto_reply_mode' => 'off',
        ]);
        $im = $this->incoming($account, $channel, '5541999990000@s.whatsapp.net');

        $log = $this->sender()->send('auto', $channel, $im->remote_jid, 'resp', $im->id);

        $this->assertSame('blocked', $log->status);
        $this->assertSame('opt_out', $log->motivo);
        Http::assertSentCount(0);
    }

    public function test_auto_fora_da_janela(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 29, 22, 0, 0, 'America/Sao_Paulo'));
        $this->fakeOk();
        [$account, $channel] = $this->scaffold();
        $im = $this->incoming($account, $channel, '5541999990000@s.whatsapp.net');

        $log = $this->sender()->send('auto', $channel, $im->remote_jid, 'resp', $im->id);

        $this->assertSame('blocked', $log->status);
        $this->assertSame('fora_da_janela', $log->motivo);
        Http::assertSentCount(0);
    }

    public function test_auto_envia_dentro_das_condicoes(): void
    {
        $this->fakeOk();
        [$account, $channel] = $this->scaffold();
        $im = $this->incoming($account, $channel, '5541999990000@s.whatsapp.net');

        $log = $this->sender()->send('auto', $channel, $im->remote_jid, 'resp', $im->id);

        $this->assertSame('sent', $log->status);
        $this->assertSame('PROVIDERMSG123', $log->provider_message_id);
        Http::assertSentCount(1);
    }

    // ---- C2: janela avaliada em America/Sao_Paulo ---------------------------

    public function test_janela_19h30_sao_paulo_esta_dentro(): void
    {
        // 19:30 SP = 22:30 UTC. Em UTC cairia FORA (>20:00); em SP esta DENTRO de 08-20.
        Carbon::setTestNow(Carbon::create(2026, 6, 29, 19, 30, 0, 'America/Sao_Paulo'));
        $this->fakeOk();
        [$account, $channel] = $this->scaffold();
        $im = $this->incoming($account, $channel, '5541999990000@s.whatsapp.net');

        $log = $this->sender()->send('auto', $channel, $im->remote_jid, 'resp', $im->id);

        $this->assertSame('sent', $log->status);
        Http::assertSentCount(1);
    }

    public function test_janela_21h_sao_paulo_esta_fora(): void
    {
        // 21:00 SP esta FORA de 08-20.
        Carbon::setTestNow(Carbon::create(2026, 6, 29, 21, 0, 0, 'America/Sao_Paulo'));
        $this->fakeOk();
        [$account, $channel] = $this->scaffold();
        $im = $this->incoming($account, $channel, '5541999990000@s.whatsapp.net');

        $log = $this->sender()->send('auto', $channel, $im->remote_jid, 'resp', $im->id);

        $this->assertSame('blocked', $log->status);
        $this->assertSame('fora_da_janela', $log->motivo);
        Http::assertSentCount(0);
    }

    public function test_auto_idempotente_mesmo_incoming(): void
    {
        $this->fakeOk();
        [$account, $channel] = $this->scaffold(['min_interval_seconds' => 0]);
        $im = $this->incoming($account, $channel, '5541999990000@s.whatsapp.net');

        $a = $this->sender()->send('auto', $channel, $im->remote_jid, 'resp', $im->id);
        $b = $this->sender()->send('auto', $channel, $im->remote_jid, 'resp', $im->id);

        $this->assertSame('sent', $a->status);
        $this->assertSame($a->id, $b->id); // mesma linha, nao reenviou
        $this->assertSame(1, AutoReplyLog::where('incoming_message_id', $im->id)->count());
        Http::assertSentCount(1);
    }

    public function test_auto_rate_por_contato(): void
    {
        $this->fakeOk();
        [$account, $channel] = $this->scaffold(['min_interval_seconds' => 0, 'contact_rate_seconds' => 1800]);
        $jid = '5541999990000@s.whatsapp.net';
        $im1 = $this->incoming($account, $channel, $jid);
        $im2 = $this->incoming($account, $channel, $jid);

        $a = $this->sender()->send('auto', $channel, $jid, 'resp', $im1->id);
        $b = $this->sender()->send('auto', $channel, $jid, 'resp', $im2->id);

        $this->assertSame('sent', $a->status);
        $this->assertSame('blocked', $b->status);
        $this->assertSame('rate_contato', $b->motivo);
        Http::assertSentCount(1);
    }

    // ---- R2: re-check volatil imediatamente antes do POST -------------------

    public function test_r2_recheck_barra_antes_do_post(): void
    {
        $this->fakeOk();
        [$account, $channel] = $this->scaffold();
        $im = $this->incoming($account, $channel, '5541999990000@s.whatsapp.net');

        // Guard que LIBERA no check inicial mas BLOQUEIA no re-check volatil
        // (simula kill switch virando OFF entre o enfileiramento e o envio).
        $stubGuard = new class(app(Throttle::class)) extends AntiBanGuard {
            public function check(string $mode, int $accountId, string $jid, bool $fromMe = false, ?int $ruleId = null): GuardDecision
            {
                return GuardDecision::allow();
            }

            public function volatileRecheck(int $accountId, string $jid): GuardDecision
            {
                return GuardDecision::block('kill_switch');
            }
        };

        $sender = new Sender(app(\App\Contracts\WhatsappGateway::class), $stubGuard, app(Throttle::class), app(\App\Whatsapp\Secrets\SecretVault::class));
        $log = $sender->send('auto', $channel, $im->remote_jid, 'resp', $im->id);

        $this->assertSame('blocked', $log->status);
        $this->assertSame('kill_switch', $log->motivo);
        Http::assertSentCount(0); // nao chegou a postar
    }
}
