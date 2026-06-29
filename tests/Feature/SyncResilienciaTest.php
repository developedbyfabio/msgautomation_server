<?php

namespace Tests\Feature;

use App\Jobs\ProcessIncomingWhatsappMessage;
use App\Livewire\Conversas;
use App\Livewire\StatusConexao;
use App\Models\Account;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A2/A3/B — resiliencia da sincronizacao e clareza da auto-resposta.
 * Sem envio real, sem desconexao real (HTTP mockado).
 */
class SyncResilienciaTest extends TestCase
{
    use RefreshDatabase;

    private function instancia(): string
    {
        return (string) config('services.evolution.instance');
    }

    private function canal(string $status = 'connected'): Account
    {
        $account = Account::create(['name' => 'Teste']);
        Channel::create(['account_id' => $account->id, 'instance' => $this->instancia(), 'status' => $status]);

        return $account;
    }

    // ---- Honestidade do status de conexao ----------------------------------

    public function test_status_tolera_blip_unico(): void
    {
        Http::fake(['*/instance/connectionState/*' => Http::response([], 500)]);
        $this->canal('connected');

        // 1 falha (blip) -> NAO rebaixa.
        Livewire::test(StatusConexao::class)->call('refresh')->assertSet('blips', 1);

        $this->assertDatabaseHas('channels', ['instance' => $this->instancia(), 'status' => 'connected']);
    }

    public function test_status_desconhecido_sustentado_rebaixa(): void
    {
        Http::fake(['*/instance/connectionState/*' => Http::response([], 500)]);
        $this->canal('connected');

        // 2 falhas seguidas (sustentado) -> honesto: marca desconectado.
        Livewire::test(StatusConexao::class)
            ->call('refresh')
            ->call('refresh')
            ->assertSet('state', 'close');

        $this->assertDatabaseHas('channels', ['instance' => $this->instancia(), 'status' => 'disconnected']);
    }

    public function test_status_open_zera_blips(): void
    {
        $this->canal('connected');

        // Sequencia: 1a leitura falha (blip), 2a volta open -> zera o contador.
        Http::fake(['*/instance/connectionState/*' => Http::sequence()
            ->push([], 500)
            ->push(['instance' => ['state' => 'open']], 200),
        ]);

        Livewire::test(StatusConexao::class)
            ->call('refresh')->assertSet('blips', 1)
            ->call('refresh')->assertSet('blips', 0)->assertSet('state', 'open');
    }

    // ---- Alinhamento webhook -> fila default -------------------------------

    public function test_webhook_enfileira_na_fila_default(): void
    {
        Queue::fake();
        config(['services.webhook.secret' => 'segredo-de-teste', 'services.webhook.header' => 'X-Webhook-Secret']);

        $payload = [
            'event' => 'messages.upsert',
            'instance' => $this->instancia(),
            'data' => [
                'key' => ['id' => 'EVO-Q1', 'fromMe' => false, 'remoteJid' => '5541999990000@s.whatsapp.net'],
                'pushName' => 'X', 'messageType' => 'conversation',
                'message' => ['conversation' => 'oi'], 'messageTimestamp' => time(),
            ],
        ];

        $this->withHeaders(['X-Webhook-Secret' => 'segredo-de-teste'])
            ->postJson('/webhook/evolution', $payload)->assertOk();

        // O worker systemd consome a fila 'default'. O webhook nao deve rotear pra outra
        // fila: o job vai sem queue customizada (null) -> cai na default da conexao.
        Queue::assertPushed(
            ProcessIncomingWhatsappMessage::class,
            fn ($job) => in_array($job->queue, [null, 'default'], true),
        );
    }

    // ---- B: clareza da auto-resposta ---------------------------------------

    public function test_banner_off_aparece_quando_kill_switch_off(): void
    {
        $account = $this->canal('connected');
        AutoReplySetting::create(['account_id' => $account->id, 'enabled' => false]);
        $this->actingAs(User::factory()->create());

        $this->get('/conversas')
            ->assertOk()
            ->assertSee('Robo desligado')
            ->assertSee('nunca as mensagens que voce mesmo envia');
    }

    public function test_banner_off_some_quando_ligado(): void
    {
        $account = $this->canal('connected');
        AutoReplySetting::create(['account_id' => $account->id, 'enabled' => true]);
        $this->actingAs(User::factory()->create());

        $this->get('/conversas')->assertOk()->assertDontSee('Robo desligado');
    }

    public function test_dica_from_me_no_painel_do_contato(): void
    {
        $account = $this->canal('connected');
        $jid = '5541999990000@s.whatsapp.net';
        Contact::create(['account_id' => $account->id, 'remote_jid' => $jid, 'auto_reply_mode' => 'default']);

        Livewire::test(Conversas::class)
            ->set('selectedJid', $jid)
            ->call('openContactPanel')
            ->assertSee('mensagens proprias sao ignoradas');
    }
}
