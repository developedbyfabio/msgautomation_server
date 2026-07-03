<?php

namespace Tests\Feature;

use App\Livewire\Logs;
use App\Models\Account;
use App\Models\AutoReplyLog;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\SystemEvent;
use App\Models\UnmatchedMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 02 — /logs (somente leitura) + persistencia do status FAILED da Meta.
 * O buraco do 130497 nunca mais: failed assincrono marca o envio como falho e
 * vira evento LEGIVEL (code + title). Horario exibido em SP; isolamento MT.
 */
class LogsPageTest extends TestCase
{
    use RefreshDatabase;

    private const PNID = '111000111000111';
    private const APP_SECRET = 'segredo-logs';

    private Account $account;
    private Channel $cloud;
    private Channel $evo;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.single_account_fallback' => false]);

        $this->account = Account::create(['name' => 'Conta A']);
        $this->evo = Channel::create(['account_id' => $this->account->id, 'instance' => 'evo-a', 'provider' => 'evolution', 'webhook_token' => 'tok-evo-a', 'status' => 'connected']);
        $this->cloud = Channel::create([
            'account_id' => $this->account->id, 'instance' => self::PNID, 'provider' => 'cloud_api',
            'webhook_token' => 'tok-cloud-a', 'status' => 'connected',
            'credentials' => ['access_token' => 't', 'phone_number_id' => self::PNID, 'waba_id' => 'w', 'verify_token' => 'v', 'app_secret' => self::APP_SECRET],
        ]);
        AutoReplySetting::create(['account_id' => $this->account->id]);

        $this->user = User::create(['name' => 'A', 'email' => 'a@teste.local', 'password' => Hash::make('senha-forte-123')]);
        $this->user->accounts()->attach($this->account->id, ['role' => 'owner']);
    }

    /** Tela com o contexto que o middleware web daria (conta do vinculo). */
    private function tela()
    {
        app(\App\Tenancy\AccountContext::class)->set($this->account->id);

        return Livewire::actingAs($this->user)->test(Logs::class);
    }

    private function logEnviado(string $wamid, ?int $channelId = null): AutoReplyLog
    {
        return AutoReplyLog::withoutAccountScope()->create([
            'account_id' => $this->account->id,
            'channel_id' => $channelId ?: $this->cloud->id,
            'remote_jid' => '554199990000@s.whatsapp.net',
            'mode' => 'auto',
            'response_text' => 'Resposta de teste.',
            'status' => 'sent',
            'provider_message_id' => $wamid,
            'sent_at' => now(),
        ]);
    }

    private function postStatusFailed(string $wamid, string $code = '130497', string $title = 'Business account is restricted from messaging users in this country.')
    {
        $payload = ['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'waba', 'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => ['display_phone_number' => 'x', 'phone_number_id' => self::PNID],
                    'statuses' => [[
                        'id' => $wamid, 'status' => 'failed',
                        'timestamp' => (string) now()->timestamp,
                        'recipient_id' => '554199990000',
                        'errors' => [['code' => (int) $code, 'title' => $title, 'message' => $title]],
                    ]],
                ],
            ]],
        ]]];
        $raw = json_encode($payload);

        return $this->call('POST', '/webhook/cloud/tok-cloud-a', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $raw, self::APP_SECRET),
        ], $raw);
    }

    // ---- o coracao: failed persistido ----------------------------------------------

    public function test_status_failed_marca_o_envio_como_falho_e_vira_evento_legivel(): void
    {
        $log = $this->logEnviado('wamid.FAIL1');

        $this->postStatusFailed('wamid.FAIL1')->assertOk(); // 200 rapido continua

        $log->refresh();
        $this->assertSame('failed', $log->status);
        $this->assertSame('meta_130497', $log->motivo);

        $ev = SystemEvent::withoutAccountScope()->where('ref', 'status-failed:wamid.FAIL1')->firstOrFail();
        $this->assertSame('envio_falhou', $ev->type);
        $this->assertSame('error', $ev->level);
        $this->assertSame('130497', $ev->detail['code']);
        $this->assertStringContainsString('restricted', $ev->detail['title']);

        // Re-entrega da Meta NAO duplica o evento.
        $this->postStatusFailed('wamid.FAIL1')->assertOk();
        $this->assertSame(1, SystemEvent::withoutAccountScope()->where('ref', 'status-failed:wamid.FAIL1')->count());

        // E aparece na pagina com code + descricao legivel.
        $this->tela()
            ->assertSee('130497')
            ->assertSee('Meta recusou o envio');
    }

    // ---- categorias -------------------------------------------------------------------

    public function test_envio_ok_recebida_e_sem_match_aparecem_nas_categorias(): void
    {
        $this->logEnviado('wamid.OK1');
        \App\Models\IncomingMessage::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'channel_id' => $this->cloud->id,
            'instance' => self::PNID, 'evolution_message_id' => 'wamid.RX1',
            'remote_jid' => '554188880000@s.whatsapp.net', 'from_me' => false,
            'push_name' => 'Cliente Cloud', 'type' => 'text', 'text' => 'oi tudo bem',
            'raw_payload' => [], 'received_at' => now(),
        ]);
        $contato = Contact::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'remote_jid' => '554177770000@s.whatsapp.net',
            'auto_reply_mode' => 'on', 'push_name' => 'Sem Match',
        ]);
        app(\App\Tenancy\AccountContext::class)->runAs($this->account->id, fn () => UnmatchedMessage::record($this->account->id, $contato->remote_jid, 'frase misteriosa'));

        $this->tela()
            ->assertSee('Resposta enviada pra 554199990000')
            ->assertSee('Cliente Cloud')
            ->assertSee('Sem resposta pra Sem Match')
            ->assertSee('frase misteriosa');
    }

    public function test_filtros_por_tipo_canal_e_periodo(): void
    {
        $this->logEnviado('wamid.OKC', $this->cloud->id);
        $this->logEnviado('wamid.OKE', $this->evo->id);
        $falho = $this->logEnviado('wamid.FAILF', $this->cloud->id);
        $this->postStatusFailed('wamid.FAILF', '131030', 'Recipient phone number not in allowed list')->assertOk();

        // Tipo: so falhas.
        $this->tela()
            ->set('tipo', 'envio_falhou')
            ->assertSee('131030')
            ->assertDontSee('Resposta enviada pra');

        // Canal: evolution nao mostra o envio do cloud.
        $this->tela()
            ->set('tipo', 'envio_ok')->set('canal', 'evolution')
            ->assertSee('(Evolution)')
            ->assertDontSee('(Cloud)');

        // Periodo: evento de 8 dias atras some no filtro de 7d.
        SystemEvent::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'channel_id' => $this->cloud->id,
            'type' => 'canal', 'level' => 'info', 'title' => 'EVENTO ANTIGO DEMAIS',
            'occurred_at' => now()->subDays(8),
        ]);
        $this->tela()
            ->set('periodo', '7d')
            ->assertDontSee('EVENTO ANTIGO DEMAIS');
    }

    public function test_mudanca_de_status_de_canal_vira_evento(): void
    {
        $this->evo->update(['status' => 'disconnected']);

        $this->assertDatabaseHas('system_events', [
            'account_id' => $this->account->id, 'channel_id' => $this->evo->id,
            'type' => 'canal', 'level' => 'warning',
        ]);
        $this->tela()
            ->set('tipo', 'canal')
            ->assertSee('connected -> disconnected');
    }

    // ---- isolamento + fuso ---------------------------------------------------------------

    public function test_usuario_nao_ve_eventos_de_outra_conta(): void
    {
        $b = Account::create(['name' => 'Conta B']);
        SystemEvent::withoutAccountScope()->create([
            'account_id' => $b->id, 'type' => 'envio_falhou', 'level' => 'error',
            'title' => 'FALHA SECRETA DA CONTA B', 'occurred_at' => now(),
        ]);
        AutoReplyLog::withoutAccountScope()->create([
            'account_id' => $b->id, 'remote_jid' => '550000000000@s.whatsapp.net',
            'mode' => 'auto', 'response_text' => 'RESPOSTA DA CONTA B', 'status' => 'sent', 'sent_at' => now(),
        ]);

        $this->tela()
            ->assertDontSee('FALHA SECRETA DA CONTA B')
            ->assertDontSee('RESPOSTA DA CONTA B');
    }

    public function test_horario_exibido_em_sao_paulo_nao_utc(): void
    {
        SystemEvent::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'type' => 'canal', 'level' => 'info',
            'title' => 'EVENTO DO MEIO-DIA UTC',
            'occurred_at' => Carbon::parse('2026-07-03 12:00:00', 'UTC'),
        ]);
        Carbon::setTestNow(Carbon::parse('2026-07-03 13:00:00', 'UTC')); // dentro de 24h

        $this->tela()
            ->set('tipo', 'canal')
            ->assertSee('09:00:00')      // 12 UTC = 09 SP
            ->assertDontSee('12:00:00');

        Carbon::setTestNow();
    }
}
