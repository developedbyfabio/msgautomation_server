<?php

namespace Tests\Feature;

use App\Livewire\Conversas;
use App\Models\Account;
use App\Models\AutoReplyLog;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactChannelWindow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 06 — anexos parte C (audio). Evolution = endpoint proprio
 * sendWhatsAppAudio (base64); Cloud = upload -> media_id -> type=audio (SEM
 * caption — WhatsApp nao suporta em audio); formatos fora da lista da Meta
 * recusados; janela de 24h vale; isolamento por conta. HTTP SEMPRE mockado.
 * FONTE ABSTRATA: sendAudio recebe caminho de arquivo — base do audio-robo.
 */
class AnexoAudioTest extends TestCase
{
    use RefreshDatabase;

    private const JID = '5541999990000@s.whatsapp.net';
    private const PNID = '111000111000111';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function accountEvolution(): Account
    {
        $account = Account::create(['name' => 'Teste']);
        Channel::create(['account_id' => $account->id, 'instance' => 'fabio-pessoal', 'status' => 'connected']);
        AutoReplySetting::create(['account_id' => $account->id, 'min_interval_seconds' => 0]);

        return $account;
    }

    private function accountCloud(): array
    {
        $account = Account::create(['name' => 'Teste Cloud']);
        $canal = Channel::create([
            'account_id' => $account->id, 'instance' => self::PNID, 'provider' => 'cloud_api',
            'webhook_token' => 'tok-cloud', 'status' => 'connected',
            'credentials' => ['access_token' => 'tok', 'phone_number_id' => self::PNID, 'waba_id' => 'w', 'verify_token' => 'v', 'app_secret' => 's'],
        ]);
        AutoReplySetting::create(['account_id' => $account->id, 'min_interval_seconds' => 0]);

        return [$account, $canal];
    }

    private function mp3(string $nome = 'recado.mp3'): UploadedFile
    {
        // createWithContent: bytes REAIS (o create() sparse chega vazio no fluxo
        // de upload de teste do Livewire) — o assert de base64 fica significativo.
        return UploadedFile::fake()->createWithContent($nome, "ID3\x03\x00" . str_repeat("\xFF\xFB\x90\x00", 512));
    }

    // ---- Evolution: endpoint proprio de audio ------------------------------------------

    public function test_audio_pela_evolution_usa_sendWhatsAppAudio_persiste_e_aparece_com_player(): void
    {
        Http::fake(['*' => Http::response(['key' => ['id' => 'PMID-AUD']], 201)]);
        $this->accountEvolution();

        Livewire::test(Conversas::class)
            ->set('selectedJid', self::JID)
            ->set('body', 'esse texto NAO vira legenda')
            ->set('anexo', $this->mp3())
            ->call('sendManual')
            ->assertSet('anexo', null)
            ->assertSet('body', 'esse texto NAO vira legenda'); // audio nao leva legenda: texto FICA

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/message/sendWhatsAppAudio/fabio-pessoal')
            && ($r['audio'] ?? '') !== '' && base64_decode((string) $r['audio'], true) !== false);

        $log = AutoReplyLog::query()->firstOrFail();
        $this->assertSame('sent', $log->status);
        $this->assertSame('audio/mpeg', $log->media_mime);
        $this->assertSame('recado.mp3', $log->media_name);
        $this->assertSame('', (string) $log->response_text); // sem legenda no log
        Storage::disk('local')->assertExists($log->media_path);

        // Bolha com player de audio apontando pra rota escopada.
        Livewire::test(Conversas::class)
            ->set('selectedJid', self::JID)
            ->assertSee('<audio', false)
            ->assertSee(route('media.show', $log->id));
    }

    // ---- validacao de formato ------------------------------------------------------------

    public function test_formato_de_audio_nao_aceito_pelo_canal_e_recusado(): void
    {
        Http::fake();
        $this->accountEvolution();

        // flac nao esta na lista da Meta — recusado ANTES de qualquer envio.
        Livewire::test(Conversas::class)
            ->set('selectedJid', self::JID)
            ->set('anexo', UploadedFile::fake()->create('musica.flac', 200, 'audio/flac'))
            ->assertHasErrors('anexo')
            ->assertSet('anexo', null);

        Http::assertNothingSent();
        $this->assertSame(0, AutoReplyLog::withoutAccountScope()->count());
    }

    // ---- Cloud: duas etapas, type=audio, sem caption ---------------------------------------

    public function test_cloud_faz_upload_e_envia_type_audio_sem_caption(): void
    {
        [$account, $canal] = $this->accountCloud();
        $contato = Contact::withoutAccountScope()->create([
            'account_id' => $account->id, 'remote_jid' => self::JID, 'auto_reply_mode' => 'on',
        ]);
        ContactChannelWindow::touchWindow($account->id, (int) $contato->id, (int) $canal->id); // janela ABERTA

        Http::fake([
            '*/' . self::PNID . '/media' => Http::response(['id' => 'MEDIA-AUD-9'], 200),
            '*/' . self::PNID . '/messages' => Http::response(['messages' => [['id' => 'wamid.AUD1']]], 200),
        ]);

        Livewire::test(Conversas::class)
            ->set('selectedJid', self::JID)
            ->set('body', 'texto que fica')
            ->set('anexo', $this->mp3('voz.mp3'))
            ->call('sendManual');

        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/' . self::PNID . '/media')
            && $r->isMultipart());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/' . self::PNID . '/messages')
            && ($r['type'] ?? null) === 'audio'
            && data_get($r, 'audio.id') === 'MEDIA-AUD-9'
            && data_get($r, 'audio.caption') === null); // Meta nao aceita caption em audio

        $log = AutoReplyLog::withoutAccountScope()->firstOrFail();
        $this->assertSame('sent', $log->status);
        $this->assertSame('wamid.AUD1', $log->provider_message_id);
    }

    public function test_cloud_fora_da_janela_de_24h_bloqueia_audio(): void
    {
        [$account, $canal] = $this->accountCloud();
        $contato = Contact::withoutAccountScope()->create([
            'account_id' => $account->id, 'remote_jid' => self::JID, 'auto_reply_mode' => 'on',
        ]);
        ContactChannelWindow::touchWindow($account->id, (int) $contato->id, (int) $canal->id, now()->subHours(25));

        Http::fake();

        Livewire::test(Conversas::class)
            ->set('selectedJid', self::JID)
            ->set('anexo', $this->mp3())
            ->call('sendManual')
            ->assertSet('sendStatus', 'Bloqueado por freio: janela_24h');

        Http::assertNothingSent();
        $this->assertSame('blocked', AutoReplyLog::withoutAccountScope()->firstOrFail()->status);
    }

    // ---- isolamento ---------------------------------------------------------------------

    public function test_conta_a_nao_acessa_audio_da_conta_b(): void
    {
        config(['tenancy.single_account_fallback' => false]);

        $a = Account::create(['name' => 'A']);
        $b = Account::create(['name' => 'B']);
        $userA = User::create(['name' => 'A', 'email' => 'a@teste.local', 'password' => Hash::make('senha-forte-123')]);
        $userA->accounts()->attach($a->id, ['role' => 'owner']);

        Storage::disk('local')->put('media/' . $b->id . '/5500/voz.mp3', 'MP3DATA');
        $logB = AutoReplyLog::withoutAccountScope()->create([
            'account_id' => $b->id, 'remote_jid' => '5500@s.whatsapp.net', 'mode' => 'manual',
            'response_text' => '', 'media_path' => 'media/' . $b->id . '/5500/voz.mp3',
            'media_mime' => 'audio/mpeg', 'media_name' => 'voz.mp3',
            'status' => 'sent', 'sent_at' => now(),
        ]);

        $this->actingAs($userA)->get('/media/' . $logB->id)->assertNotFound();
    }
}
