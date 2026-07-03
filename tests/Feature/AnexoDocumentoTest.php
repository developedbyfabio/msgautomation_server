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
 * Prompt 05 — anexos parte B (PDF/documentos). Mesma infra da parte A com tipo
 * document: Evolution = sendMedia mediatype document; Cloud = upload -> media_id
 * -> type=document com FILENAME original; janela de 24h vale; isolamento por
 * conta. HTTP SEMPRE mockado (nunca envio real).
 */
class AnexoDocumentoTest extends TestCase
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

    private function pdf(string $nome = 'orcamento.pdf', int $kb = 100): UploadedFile
    {
        return UploadedFile::fake()->create($nome, $kb, 'application/pdf');
    }

    // ---- Evolution ------------------------------------------------------------------

    public function test_pdf_pela_evolution_usa_mediatype_document_persiste_e_aparece_na_conversa(): void
    {
        Http::fake(['*' => Http::response(['key' => ['id' => 'PMID-DOC']], 201)]);
        $this->accountEvolution();

        Livewire::test(Conversas::class)
            ->set('selectedJid', self::JID)
            ->set('body', 'segue o orçamento')
            ->set('anexo', $this->pdf())
            ->call('sendManual')
            ->assertSet('anexo', null)
            ->assertSet('body', '');

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/message/sendMedia/fabio-pessoal')
            && $r['mediatype'] === 'document'
            && $r['mimetype'] === 'application/pdf'
            && $r['fileName'] === 'orcamento.pdf' // nome ORIGINAL, nao o uuid do disco
            && $r['caption'] === 'segue o orçamento');

        $log = AutoReplyLog::query()->firstOrFail();
        $this->assertSame('sent', $log->status);
        $this->assertSame('application/pdf', $log->media_mime);
        $this->assertSame('orcamento.pdf', $log->media_name);
        Storage::disk('local')->assertExists($log->media_path);

        // Aparece na thread como documento (nome do arquivo no card).
        Livewire::test(Conversas::class)
            ->set('selectedJid', self::JID)
            ->assertSee('orcamento.pdf')
            ->assertSee(route('media.show', $log->id));
    }

    // ---- validacao -------------------------------------------------------------------

    public function test_documento_acima_de_10mb_e_recusado(): void
    {
        Http::fake();
        $this->accountEvolution();

        Livewire::test(Conversas::class)
            ->set('selectedJid', self::JID)
            ->set('anexo', $this->pdf('gigante.pdf', 11000))
            ->assertHasErrors('anexo')
            ->assertSet('anexo', null);

        Http::assertNothingSent();
        $this->assertSame(0, AutoReplyLog::withoutAccountScope()->count());
    }

    // ---- Cloud: duas etapas + filename ------------------------------------------------

    public function test_cloud_faz_upload_e_envia_type_document_com_filename(): void
    {
        [$account, $canal] = $this->accountCloud();
        $contato = Contact::withoutAccountScope()->create([
            'account_id' => $account->id, 'remote_jid' => self::JID, 'auto_reply_mode' => 'on',
        ]);
        ContactChannelWindow::touchWindow($account->id, (int) $contato->id, (int) $canal->id); // janela ABERTA

        Http::fake([
            '*/' . self::PNID . '/media' => Http::response(['id' => 'MEDIA-DOC-7'], 200),
            '*/' . self::PNID . '/messages' => Http::response(['messages' => [['id' => 'wamid.DOC1']]], 200),
        ]);

        Livewire::test(Conversas::class)
            ->set('selectedJid', self::JID)
            ->set('body', 'contrato em anexo')
            ->set('anexo', $this->pdf('contrato.pdf'))
            ->call('sendManual');

        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/' . self::PNID . '/media')
            && $r->isMultipart());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/' . self::PNID . '/messages')
            && ($r['type'] ?? null) === 'document'
            && data_get($r, 'document.id') === 'MEDIA-DOC-7'
            && data_get($r, 'document.filename') === 'contrato.pdf'
            && data_get($r, 'document.caption') === 'contrato em anexo');

        $log = AutoReplyLog::withoutAccountScope()->firstOrFail();
        $this->assertSame('sent', $log->status);
        $this->assertSame('wamid.DOC1', $log->provider_message_id);
        $this->assertSame('contrato.pdf', $log->media_name);
    }

    public function test_cloud_fora_da_janela_de_24h_bloqueia_documento(): void
    {
        [$account, $canal] = $this->accountCloud();
        $contato = Contact::withoutAccountScope()->create([
            'account_id' => $account->id, 'remote_jid' => self::JID, 'auto_reply_mode' => 'on',
        ]);
        ContactChannelWindow::touchWindow($account->id, (int) $contato->id, (int) $canal->id, now()->subHours(25));

        Http::fake();

        Livewire::test(Conversas::class)
            ->set('selectedJid', self::JID)
            ->set('anexo', $this->pdf())
            ->call('sendManual')
            ->assertSet('sendStatus', 'Bloqueado por freio: janela_24h');

        Http::assertNothingSent();
        $this->assertSame('blocked', AutoReplyLog::withoutAccountScope()->firstOrFail()->status);
    }

    // ---- isolamento ---------------------------------------------------------------------

    public function test_conta_a_nao_acessa_documento_da_conta_b(): void
    {
        config(['tenancy.single_account_fallback' => false]);

        $a = Account::create(['name' => 'A']);
        $b = Account::create(['name' => 'B']);
        $userA = User::create(['name' => 'A', 'email' => 'a@teste.local', 'password' => Hash::make('senha-forte-123')]);
        $userA->accounts()->attach($a->id, ['role' => 'owner']);

        Storage::disk('local')->put('media/' . $b->id . '/5500/doc.pdf', '%PDF-1.4 conteudo');
        $logB = AutoReplyLog::withoutAccountScope()->create([
            'account_id' => $b->id, 'remote_jid' => '5500@s.whatsapp.net', 'mode' => 'manual',
            'response_text' => '', 'media_path' => 'media/' . $b->id . '/5500/doc.pdf',
            'media_mime' => 'application/pdf', 'media_name' => 'confidencial.pdf',
            'status' => 'sent', 'sent_at' => now(),
        ]);

        $this->actingAs($userA)->get('/media/' . $logB->id)->assertNotFound();
    }
}
