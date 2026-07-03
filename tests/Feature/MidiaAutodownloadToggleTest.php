<?php

namespace Tests\Feature;

use App\Jobs\DownloadIncomingMedia;
use App\Jobs\ProcessIncomingWhatsappMessage;
use App\Livewire\Configuracoes;
use App\Models\Account;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Tenancy\AccountContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 14, Parte A — auto-download de midia recebida como opcao POR CONTA.
 * Toggle persiste; o gate liga/desliga o dispatch do DownloadIncomingMedia no
 * inbound. Precedencia: escolha da tela (coluna) MANDA; null = default do .env.
 */
class MidiaAutodownloadToggleTest extends TestCase
{
    use RefreshDatabase;

    private function evoImagePayload(): array
    {
        return ['event' => 'messages.upsert', 'instance' => 'fabio-pessoal', 'data' => [
            'key' => ['id' => 'IMGWIRE', 'remoteJid' => '554188887777@s.whatsapp.net', 'fromMe' => false],
            'messageType' => 'imageMessage', 'message' => ['imageMessage' => ['mimetype' => 'image/jpeg']],
            'messageTimestamp' => now()->timestamp,
        ]];
    }

    private function channel(Account $a): Channel
    {
        return Channel::create([
            'account_id' => $a->id, 'instance' => 'fabio-pessoal', 'provider' => 'evolution',
            'webhook_token' => 'tok-' . $a->id, 'status' => 'connected',
        ]);
    }

    public function test_toggle_persiste_a_escolha_por_conta(): void
    {
        $a = Account::create(['name' => 'A']);
        app(AccountContext::class)->set($a->id);

        // Estado inicial reflete o default do .env (phpunit: false).
        Livewire::test(Configuracoes::class)
            ->assertSet('media_autodownload', false)
            ->call('toggleMediaAutodownload')
            ->assertSet('media_autodownload', true);

        $this->assertTrue((bool) AutoReplySetting::where('account_id', $a->id)->value('media_autodownload'));

        // Desliga de novo: escolha explicita false, persiste.
        Livewire::test(Configuracoes::class)
            ->call('toggleMediaAutodownload')
            ->assertSet('media_autodownload', false);
        $this->assertFalse((bool) AutoReplySetting::where('account_id', $a->id)->value('media_autodownload'));
    }

    public function test_desligado_nao_dispara_download(): void
    {
        Bus::fake([DownloadIncomingMedia::class]);
        $a = Account::create(['name' => 'A']);
        $c = $this->channel($a);
        AutoReplySetting::create(['account_id' => $a->id, 'media_autodownload' => false]);

        ProcessIncomingWhatsappMessage::dispatchSync($this->evoImagePayload(), $c->id);

        Bus::assertNotDispatched(DownloadIncomingMedia::class);
    }

    public function test_ligado_dispara_download(): void
    {
        Bus::fake([DownloadIncomingMedia::class]);
        $a = Account::create(['name' => 'A']);
        $c = $this->channel($a);
        AutoReplySetting::create(['account_id' => $a->id, 'media_autodownload' => true]);

        ProcessIncomingWhatsappMessage::dispatchSync($this->evoImagePayload(), $c->id);

        Bus::assertDispatched(DownloadIncomingMedia::class);
    }

    public function test_precedencia_null_cai_no_default_do_env(): void
    {
        $a = Account::create(['name' => 'A']);
        $c = $this->channel($a);
        AutoReplySetting::create(['account_id' => $a->id, 'media_autodownload' => null]);

        $payload = fn (string $id) => ['event' => 'messages.upsert', 'instance' => 'fabio-pessoal', 'data' => [
            'key' => ['id' => $id, 'remoteJid' => '554188887777@s.whatsapp.net', 'fromMe' => false],
            'messageType' => 'imageMessage', 'message' => ['imageMessage' => ['mimetype' => 'image/jpeg']],
            'messageTimestamp' => now()->timestamp,
        ]];

        // .env/config = true -> dispara mesmo com a coluna null.
        config(['services.incoming_media.download' => true]);
        Bus::fake([DownloadIncomingMedia::class]);
        ProcessIncomingWhatsappMessage::dispatchSync($payload('NULLA'), $c->id);
        Bus::assertDispatched(DownloadIncomingMedia::class);

        // .env/config = false + coluna null -> nao dispara (id distinto: evita dedupe).
        config(['services.incoming_media.download' => false]);
        Bus::fake([DownloadIncomingMedia::class]);
        ProcessIncomingWhatsappMessage::dispatchSync($payload('NULLB'), $c->id);
        Bus::assertNotDispatched(DownloadIncomingMedia::class);
    }

    public function test_config_por_conta_nao_vaza(): void
    {
        $a = Account::create(['name' => 'A']);
        $b = Account::create(['name' => 'B']);
        AutoReplySetting::create(['account_id' => $a->id, 'media_autodownload' => true]);
        AutoReplySetting::create(['account_id' => $b->id, 'media_autodownload' => false]);

        $this->assertTrue(AutoReplySetting::withoutAccountScope()->where('account_id', $a->id)->first()->mediaAutodownloadEnabled());
        $this->assertFalse(AutoReplySetting::withoutAccountScope()->where('account_id', $b->id)->first()->mediaAutodownloadEnabled());
    }
}
