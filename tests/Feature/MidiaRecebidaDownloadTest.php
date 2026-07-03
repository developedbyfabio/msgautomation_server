<?php

namespace Tests\Feature;

use App\Jobs\DownloadIncomingMedia;
use App\Jobs\ProcessIncomingWhatsappMessage;
use App\Models\Account;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\IncomingMessage;
use App\Models\SystemEvent;
use App\Models\User;
use App\Tenancy\AccountContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Prompt 13 — midia recebida, Fatia 2: baixar/armazenar/servir imagem cheia e
 * audio (Cloud + Evolution) + rota escopada por conta + fail-safe (nao derruba o
 * inbound). HTTP sempre MOCKADO (CDN/Graph/Evolution).
 */
class MidiaRecebidaDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config([
            'services.cloud_api.graph_base' => 'https://graph.facebook.com',
            'services.cloud_api.graph_version' => 'v21.0',
            'services.evolution.base_url' => 'http://evo-host:8090',
            'services.evolution.api_key' => 'chave-evo',
        ]);
    }

    private function jpeg(): string
    {
        return "\xFF\xD8\xFF\xE0" . str_repeat('x', 40) . "\xFF\xD9";
    }

    private function cloudChannel(Account $a): Channel
    {
        return Channel::create([
            'account_id' => $a->id, 'instance' => 'PNID1', 'provider' => 'cloud_api',
            'webhook_token' => 'tok-' . $a->id, 'status' => 'connected',
            'credentials' => ['access_token' => 'TOKEN', 'phone_number_id' => 'PNID1'],
        ]);
    }

    private function evoChannel(Account $a): Channel
    {
        return Channel::create([
            'account_id' => $a->id, 'instance' => 'fabio-pessoal', 'provider' => 'evolution',
            'webhook_token' => 'tok-evo-' . $a->id, 'status' => 'connected',
        ]);
    }

    private function cloudImage(Account $a, Channel $c, string $mediaId): IncomingMessage
    {
        return IncomingMessage::create([
            'account_id' => $a->id, 'channel_id' => $c->id, 'instance' => $c->instance,
            'evolution_message_id' => 'wamid.' . $mediaId, 'remote_jid' => '554188887777@s.whatsapp.net',
            'from_me' => false, 'type' => 'image', 'text' => null, 'received_at' => now(),
            'raw_payload' => ['entry' => [['changes' => [['value' => ['messages' => [[
                'type' => 'image', 'image' => ['id' => $mediaId, 'mime_type' => 'image/jpeg'],
            ]]]]]]]],
        ]);
    }

    // ---- Cloud: imagem cheia ---------------------------------------------------

    public function test_cloud_baixa_imagem_por_media_id_e_armazena(): void
    {
        $a = Account::create(['name' => 'A']);
        $c = $this->cloudChannel($a);
        $msg = $this->cloudImage($a, $c, 'MEDIA123');

        Http::fake([
            'graph.facebook.com/v21.0/MEDIA123' => Http::response(['url' => 'https://lookaside.fbsbx.com/x/MEDIA123', 'mime_type' => 'image/jpeg', 'file_size' => 50], 200),
            'lookaside.fbsbx.com/*' => Http::response($this->jpeg(), 200),
        ]);

        DownloadIncomingMedia::dispatchSync($msg->id, $a->id, $c->id);

        $msg->refresh();
        $this->assertSame('stored', $msg->media_status);
        $this->assertNotNull($msg->media_path);
        $this->assertSame('image/jpeg', $msg->media_mime);
        Storage::disk('local')->assertExists($msg->media_path);
        $this->assertStringStartsWith('media/incoming/' . $a->id . '/', $msg->media_path);
    }

    public function test_cloud_baixa_audio(): void
    {
        $a = Account::create(['name' => 'A']);
        $c = $this->cloudChannel($a);
        $msg = IncomingMessage::create([
            'account_id' => $a->id, 'channel_id' => $c->id, 'instance' => $c->instance,
            'evolution_message_id' => 'wamid.AUD', 'remote_jid' => '554188887777@s.whatsapp.net',
            'from_me' => false, 'type' => 'audio', 'text' => null, 'received_at' => now(),
            'raw_payload' => ['entry' => [['changes' => [['value' => ['messages' => [[
                'type' => 'audio', 'audio' => ['id' => 'AUDID', 'mime_type' => 'audio/ogg'],
            ]]]]]]]],
        ]);

        Http::fake([
            'graph.facebook.com/v21.0/AUDID' => Http::response(['url' => 'https://lookaside.fbsbx.com/a/AUDID', 'mime_type' => 'audio/ogg', 'file_size' => 10], 200),
            'lookaside.fbsbx.com/*' => Http::response('OggS-binario', 200),
        ]);

        DownloadIncomingMedia::dispatchSync($msg->id, $a->id, $c->id);

        $msg->refresh();
        $this->assertSame('stored', $msg->media_status);
        $this->assertSame('audio/ogg', $msg->media_mime);
        Storage::disk('local')->assertExists($msg->media_path);
    }

    // ---- Evolution: endpoint getBase64FromMediaMessage -------------------------

    public function test_evolution_baixa_imagem_via_base64_endpoint(): void
    {
        $a = Account::create(['name' => 'A']);
        $c = $this->evoChannel($a);
        $msg = IncomingMessage::create([
            'account_id' => $a->id, 'channel_id' => $c->id, 'instance' => $c->instance,
            'evolution_message_id' => 'EVOIMG', 'remote_jid' => '554188887777@s.whatsapp.net',
            'from_me' => false, 'type' => 'imageMessage', 'text' => null, 'received_at' => now(),
            'raw_payload' => ['data' => ['key' => ['id' => 'EVOIMG', 'remoteJid' => '554188887777@s.whatsapp.net', 'fromMe' => false],
                'message' => ['imageMessage' => ['mimetype' => 'image/jpeg']]]],
        ]);

        Http::fake([
            'evo-host:8090/chat/getBase64FromMediaMessage/*' => Http::response([
                'mimetype' => 'image/jpeg', 'base64' => base64_encode($this->jpeg()),
            ], 200),
        ]);

        DownloadIncomingMedia::dispatchSync($msg->id, $a->id, $c->id);

        $msg->refresh();
        $this->assertSame('stored', $msg->media_status);
        $this->assertSame('image/jpeg', $msg->media_mime);
        Storage::disk('local')->assertExists($msg->media_path);
    }

    public function test_evolution_baixa_audio_e_normaliza_mime_com_codecs(): void
    {
        $a = Account::create(['name' => 'A']);
        $c = $this->evoChannel($a);
        $msg = IncomingMessage::create([
            'account_id' => $a->id, 'channel_id' => $c->id, 'instance' => $c->instance,
            'evolution_message_id' => 'EVOAUD', 'remote_jid' => '554188887777@s.whatsapp.net',
            'from_me' => false, 'type' => 'audioMessage', 'text' => null, 'received_at' => now(),
            'raw_payload' => ['data' => ['key' => ['id' => 'EVOAUD', 'remoteJid' => '554188887777@s.whatsapp.net', 'fromMe' => false],
                'message' => ['audioMessage' => ['mimetype' => 'audio/ogg; codecs=opus']]]],
        ]);

        Http::fake([
            'evo-host:8090/chat/getBase64FromMediaMessage/*' => Http::response([
                'mimetype' => 'audio/ogg; codecs=opus', 'base64' => base64_encode('OggS-bin'),
            ], 200),
        ]);

        DownloadIncomingMedia::dispatchSync($msg->id, $a->id, $c->id);

        $msg->refresh();
        $this->assertSame('stored', $msg->media_status);
        $this->assertSame('audio/ogg', $msg->media_mime); // "; codecs=opus" removido
    }

    // ---- fail-safe -------------------------------------------------------------

    public function test_falha_de_download_nao_derruba_e_loga_evento(): void
    {
        $a = Account::create(['name' => 'A']);
        $c = $this->cloudChannel($a);
        $msg = $this->cloudImage($a, $c, 'BOOM');

        Http::fake(['graph.facebook.com/*' => Http::response(['error' => 'x'], 500)]);

        // NAO lanca (best-effort).
        DownloadIncomingMedia::dispatchSync($msg->id, $a->id, $c->id);

        $msg->refresh();
        $this->assertSame('failed', $msg->media_status);
        $this->assertNull($msg->media_path);
        $this->assertDatabaseHas('system_events', [
            'account_id' => $a->id, 'type' => 'midia_download_falhou', 'level' => 'warning',
        ]);
    }

    public function test_inbound_nao_quebra_quando_download_falha(): void
    {
        // Wiring: com a config LIGADA + HTTP falhando, o inbound persiste a mensagem
        // e dispara o download (sync nos testes); a falha do download nao propaga.
        config(['services.incoming_media.download' => true]);
        $a = Account::create(['name' => 'A']);
        $c = $this->evoChannel($a);
        Http::fake(['evo-host:8090/*' => Http::response([], 500)]);

        $payload = ['event' => 'messages.upsert', 'instance' => 'fabio-pessoal', 'data' => [
            'key' => ['id' => 'WIRED1', 'remoteJid' => '554188887777@s.whatsapp.net', 'fromMe' => false],
            'messageType' => 'imageMessage', 'message' => ['imageMessage' => ['mimetype' => 'image/jpeg']],
            'messageTimestamp' => now()->timestamp,
        ]];

        ProcessIncomingWhatsappMessage::dispatchSync($payload, $c->id);

        // A mensagem foi registrada (inbound intacto), mesmo com o download falhando.
        $this->assertDatabaseHas('incoming_messages', ['evolution_message_id' => 'WIRED1', 'account_id' => $a->id]);
        $msg = IncomingMessage::where('evolution_message_id', 'WIRED1')->first();
        $this->assertSame('failed', $msg->media_status);
    }

    // ---- rota de servir (escopo por conta) -------------------------------------

    private function login(Account $a): User
    {
        $u = User::create(['name' => 'Op', 'email' => 'op' . $a->id . '@x.local', 'password' => Hash::make('x-123')]);
        $u->accounts()->attach($a->id);
        $this->actingAs($u);
        $this->withSession(['tenancy.account_id' => $a->id]);

        return $u;
    }

    public function test_rota_serve_midia_pro_dono_e_404_pra_outra_conta(): void
    {
        $a = Account::create(['name' => 'A']);
        $b = Account::create(['name' => 'B']);
        $ca = $this->cloudChannel($a);
        $msg = $this->cloudImage($a, $ca, 'M1');
        Storage::disk('local')->put($p = 'media/incoming/' . $a->id . '/x/f.jpg', $this->jpeg());
        $msg->update(['media_path' => $p, 'media_mime' => 'image/jpeg', 'media_status' => 'stored']);

        // Dono (conta A): recebe o binario com content-type certo.
        $this->login($a);
        $resp = $this->get(route('media.incoming', $msg->id));
        $resp->assertOk();
        $this->assertSame('image/jpeg', $resp->headers->get('Content-Type'));

        // Outra conta (B): 404 — escopo por conta nunca vaza.
        $this->login($b);
        $this->get(route('media.incoming', $msg->id))->assertNotFound();
    }

    public function test_rota_thumb_serve_jpeg_embutido_e_404_sem_thumb(): void
    {
        $a = Account::create(['name' => 'A']);
        $c = $this->evoChannel($a);
        // imagem COM jpegThumbnail (array de bytes) e outra SEM
        $comThumb = IncomingMessage::create([
            'account_id' => $a->id, 'channel_id' => $c->id, 'instance' => $c->instance,
            'evolution_message_id' => 'T1', 'remote_jid' => '554188887777@s.whatsapp.net',
            'from_me' => false, 'type' => 'imageMessage', 'received_at' => now(),
            'raw_payload' => ['data' => ['message' => ['imageMessage' => ['jpegThumbnail' => [0xFF, 0xD8, 0xFF, 0xE0, 0x01, 0xFF, 0xD9]]]]],
        ]);
        $semThumb = IncomingMessage::create([
            'account_id' => $a->id, 'channel_id' => $c->id, 'instance' => $c->instance,
            'evolution_message_id' => 'T2', 'remote_jid' => '554188887777@s.whatsapp.net',
            'from_me' => false, 'type' => 'imageMessage', 'received_at' => now(),
            'raw_payload' => ['data' => ['message' => ['imageMessage' => []]]],
        ]);

        $this->login($a);
        $resp = $this->get(route('media.incoming', ['id' => $comThumb->id, 'thumb' => 1]));
        $resp->assertOk();
        $this->assertSame('image/jpeg', $resp->headers->get('Content-Type'));

        $this->get(route('media.incoming', ['id' => $semThumb->id, 'thumb' => 1]))->assertNotFound();
    }
}
