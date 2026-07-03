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
 * Prompt 04 — anexos parte A (imagens). Envio manual de imagem pelas conversas:
 * Evolution = sendMedia base64; Cloud = upload (/media -> media_id) + mensagem
 * referenciando o id; janela de 24h vale pra midia; storage privado POR CONTA
 * servido por rota autenticada/escopada. HTTP SEMPRE mockado (nunca envio real).
 */
class AnexoImagemTest extends TestCase
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

    // ---- Evolution: caminho proprio (sendMedia base64) --------------------------------

    public function test_envio_pela_evolution_usa_sendMedia_persiste_e_aparece_na_conversa(): void
    {
        Http::fake(['*' => Http::response(['key' => ['id' => 'PMID-IMG']], 201)]);
        $this->accountEvolution();

        Livewire::test(Conversas::class)
            ->set('selectedJid', self::JID)
            ->set('body', 'olha a foto 📷')
            ->set('foto', UploadedFile::fake()->image('foto.png', 40, 40))
            ->call('sendManual')
            ->assertSet('foto', null)
            ->assertSet('body', '');

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/message/sendMedia/fabio-pessoal')
            && $r['mediatype'] === 'image'
            && $r['mimetype'] === 'image/png'
            && $r['caption'] === 'olha a foto 📷'
            && $r['media'] !== '' && base64_decode($r['media'], true) !== false);

        $log = AutoReplyLog::query()->firstOrFail();
        $this->assertSame('sent', $log->status);
        $this->assertSame('manual', $log->mode);
        $this->assertSame('image/png', $log->media_mime);
        $this->assertNotNull($log->media_path);
        Storage::disk('local')->assertExists($log->media_path);
        $this->assertStringStartsWith('media/' . $log->account_id . '/', $log->media_path);

        // Aparece na thread (bolha com a imagem via rota escopada).
        Livewire::test(Conversas::class)
            ->set('selectedJid', self::JID)
            ->assertSee(route('media.show', $log->id));
    }

    // ---- validacao ---------------------------------------------------------------------

    public function test_tipo_invalido_e_tamanho_acima_do_limite_sao_recusados(): void
    {
        Http::fake();
        $this->accountEvolution();

        // Tipo invalido (pdf) — recusado com mensagem clara, anexo descartado.
        Livewire::test(Conversas::class)
            ->set('selectedJid', self::JID)
            ->set('foto', UploadedFile::fake()->create('nota.pdf', 100, 'application/pdf'))
            ->assertHasErrors('foto')
            ->assertSet('foto', null);

        // Acima de 5 MB — recusado.
        Livewire::test(Conversas::class)
            ->set('selectedJid', self::JID)
            ->set('foto', UploadedFile::fake()->image('grande.jpg', 40, 40)->size(6000))
            ->assertHasErrors('foto')
            ->assertSet('foto', null);

        Http::assertNothingSent();
        $this->assertSame(0, AutoReplyLog::withoutAccountScope()->count());
    }

    // ---- Cloud: duas etapas (upload -> media_id -> mensagem) ---------------------------

    public function test_cloud_faz_upload_e_envia_referenciando_media_id(): void
    {
        [$account, $canal] = $this->accountCloud();
        $contato = Contact::withoutAccountScope()->create([
            'account_id' => $account->id, 'remote_jid' => self::JID, 'auto_reply_mode' => 'on',
        ]);
        ContactChannelWindow::touchWindow($account->id, (int) $contato->id, (int) $canal->id); // janela ABERTA

        Http::fake([
            '*/' . self::PNID . '/media' => Http::response(['id' => 'MEDIA-42'], 200),
            '*/' . self::PNID . '/messages' => Http::response(['messages' => [['id' => 'wamid.IMG1']]], 200),
        ]);

        Livewire::test(Conversas::class)
            ->set('selectedJid', self::JID)
            ->set('body', 'legenda cloud')
            ->set('foto', UploadedFile::fake()->image('foto.jpg', 40, 40))
            ->call('sendManual');

        Http::assertSentCount(2);
        // Etapa 1: upload multipart com messaging_product.
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/' . self::PNID . '/media')
            && $r->isMultipart());
        // Etapa 2: mensagem type=image referenciando o media_id + caption.
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/' . self::PNID . '/messages')
            && ($r['type'] ?? null) === 'image'
            && data_get($r, 'image.id') === 'MEDIA-42'
            && data_get($r, 'image.caption') === 'legenda cloud');

        $log = AutoReplyLog::withoutAccountScope()->firstOrFail();
        $this->assertSame('sent', $log->status);
        $this->assertSame('wamid.IMG1', $log->provider_message_id);
        $this->assertNotNull($log->media_path);
    }

    public function test_cloud_fora_da_janela_de_24h_bloqueia_imagem_como_o_texto(): void
    {
        [$account, $canal] = $this->accountCloud();
        $contato = Contact::withoutAccountScope()->create([
            'account_id' => $account->id, 'remote_jid' => self::JID, 'auto_reply_mode' => 'on',
        ]);
        // Ultimo inbound ha 25h — janela FECHADA.
        ContactChannelWindow::touchWindow($account->id, (int) $contato->id, (int) $canal->id, now()->subHours(25));

        Http::fake();

        Livewire::test(Conversas::class)
            ->set('selectedJid', self::JID)
            ->set('foto', UploadedFile::fake()->image('foto.jpg', 40, 40))
            ->call('sendManual')
            ->assertSet('sendStatus', 'Bloqueado por freio: janela_24h');

        Http::assertNothingSent(); // nem upload, nem mensagem — nada de free-form fora da janela
        $this->assertSame('blocked', AutoReplyLog::withoutAccountScope()->firstOrFail()->status);
    }

    // ---- isolamento ---------------------------------------------------------------------

    public function test_conta_a_nao_acessa_midia_da_conta_b(): void
    {
        config(['tenancy.single_account_fallback' => false]);

        $a = Account::create(['name' => 'A']);
        $b = Account::create(['name' => 'B']);
        $userA = User::create(['name' => 'A', 'email' => 'a@teste.local', 'password' => Hash::make('senha-forte-123')]);
        $userA->accounts()->attach($a->id, ['role' => 'owner']);

        Storage::disk('local')->put('media/' . $b->id . '/5500/segredo.png', 'PNGDATA');
        $logB = AutoReplyLog::withoutAccountScope()->create([
            'account_id' => $b->id, 'remote_jid' => '5500@s.whatsapp.net', 'mode' => 'manual',
            'response_text' => '', 'media_path' => 'media/' . $b->id . '/5500/segredo.png',
            'media_mime' => 'image/png', 'status' => 'sent', 'sent_at' => now(),
        ]);

        // Usuario da conta A: midia da B = 404 (escopo por conta no binding).
        $this->actingAs($userA)->get('/media/' . $logB->id)->assertNotFound();

        // Dona da midia (conta B) enxerga normalmente.
        $userB = User::create(['name' => 'B', 'email' => 'b@teste.local', 'password' => Hash::make('senha-forte-123')]);
        $userB->accounts()->attach($b->id, ['role' => 'owner']);
        $this->actingAs($userB)->get('/media/' . $logB->id)->assertOk();
    }
}
