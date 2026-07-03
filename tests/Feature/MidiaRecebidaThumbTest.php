<?php

namespace Tests\Feature;

use App\Livewire\Conversas;
use App\Models\Account;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\IncomingMessage;
use App\Tenancy\AccountContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 12 — midia recebida, Fatia 1: thumbnail de IMAGEM via jpegThumbnail do
 * payload (SEM baixar/descriptografar/armazenar). Imagem sem thumbnail continua
 * mostrando so o rotulo. Isolamento por conta preservado.
 */
class MidiaRecebidaThumbTest extends TestCase
{
    use RefreshDatabase;

    /** JPEG minimo (assinatura valida) como array de bytes, com um marcador distinto. */
    private function jpegBytes(int $marcador): array
    {
        return [0xFF, 0xD8, 0xFF, 0xE0, $marcador, $marcador, 0xFF, 0xD9];
    }

    private function imagem(Account $a, Channel $c, string $jid, string $id, ?array $thumbBytes, ?string $caption = null): void
    {
        $imageMessage = [];
        if ($thumbBytes !== null) {
            $imageMessage['jpegThumbnail'] = $thumbBytes;
        }
        if ($caption !== null) {
            $imageMessage['caption'] = $caption;
        }

        IncomingMessage::create([
            'account_id' => $a->id, 'channel_id' => $c->id, 'instance' => $c->instance,
            'evolution_message_id' => $id, 'remote_jid' => $jid, 'from_me' => false,
            'type' => 'imageMessage', 'text' => $caption,
            'raw_payload' => ['data' => ['message' => ['imageMessage' => $imageMessage]]],
            'received_at' => Carbon::create(2026, 6, 29, 13, 0, 0, 'UTC'),
        ]);
    }

    public function test_imagem_com_thumbnail_renderiza_miniatura_por_url_sem_base64(): void
    {
        $a = Account::create(['name' => 'A']);
        $c = Channel::create(['account_id' => $a->id, 'instance' => 'fabio-pessoal', 'status' => 'connected']);
        $jid = '5541999990000@s.whatsapp.net';
        Contact::create(['account_id' => $a->id, 'remote_jid' => $jid, 'auto_reply_mode' => 'default']);

        $this->imagem($a, $c, $jid, 'IMG1', $this->jpegBytes(0x41), 'olha essa foto');
        $id = IncomingMessage::where('evolution_message_id', 'IMG1')->value('id');

        Livewire::test(Conversas::class)
            ->set('selectedJid', $jid)
            // Frente 3: miniatura vem por URL (?thumb=1), NAO base64 inline no HTML do poll.
            ->assertSeeHtml('/media/incoming/' . $id . '?thumb=1')
            ->assertDontSee('data:image/jpeg;base64,')
            ->assertSee('Previa (imagem completa em breve)')
            ->assertSee('olha essa foto'); // legenda preservada
    }

    public function test_imagem_cheia_baixada_abre_no_lightbox(): void
    {
        $a = Account::create(['name' => 'A']);
        $c = Channel::create(['account_id' => $a->id, 'instance' => 'fabio-pessoal', 'status' => 'connected']);
        $jid = '5541999990000@s.whatsapp.net';
        Contact::create(['account_id' => $a->id, 'remote_jid' => $jid, 'auto_reply_mode' => 'default']);

        $this->imagem($a, $c, $jid, 'IMG3', $this->jpegBytes(0x41));
        // simula o download ja concluido (Fatia 2)
        $msg = IncomingMessage::where('evolution_message_id', 'IMG3')->first();
        $msg->update(['media_path' => 'media/incoming/' . $a->id . '/x/foto.jpg', 'media_mime' => 'image/jpeg', 'media_status' => 'stored']);

        Livewire::test(Conversas::class)
            ->set('selectedJid', $jid)
            // Prompt 14: clicar abre no lightbox com a imagem CHEIA (sem ?thumb), nao em nova aba.
            ->assertSeeHtml("lightboxSrc = '" . route('media.incoming', $msg->id) . "'")
            ->assertDontSeeHtml('target="_blank"')
            ->assertDontSee('Previa (imagem completa em breve)');
    }

    public function test_imagem_sem_thumbnail_e_sem_download_cai_no_rotulo(): void
    {
        $a = Account::create(['name' => 'A']);
        $c = Channel::create(['account_id' => $a->id, 'instance' => 'fabio-pessoal', 'status' => 'connected']);
        $jid = '5541999990000@s.whatsapp.net';
        Contact::create(['account_id' => $a->id, 'remote_jid' => $jid, 'auto_reply_mode' => 'default']);

        $this->imagem($a, $c, $jid, 'IMG2', null, 'sem thumb');

        Livewire::test(Conversas::class)
            ->set('selectedJid', $jid)
            ->assertSee('Imagem')                       // rotulo padrao (nao quebra)
            ->assertDontSee('data:image/jpeg;base64,')
            ->assertDontSeeHtml('?thumb=1');
    }

    public function test_thumbnail_nao_vaza_entre_contas(): void
    {
        $a = Account::create(['name' => 'A']);
        $b = Account::create(['name' => 'B']);
        $ca = Channel::create(['account_id' => $a->id, 'instance' => 'inst-a', 'status' => 'connected']);
        $cb = Channel::create(['account_id' => $b->id, 'instance' => 'inst-b', 'status' => 'connected']);
        $jid = '5541999990000@s.whatsapp.net';
        Contact::create(['account_id' => $a->id, 'remote_jid' => $jid, 'auto_reply_mode' => 'default']);
        Contact::create(['account_id' => $b->id, 'remote_jid' => $jid, 'auto_reply_mode' => 'default']);

        $this->imagem($a, $ca, $jid, 'A1', $this->jpegBytes(0x41));
        $this->imagem($b, $cb, $jid, 'B1', $this->jpegBytes(0x42));
        $idA = IncomingMessage::where('evolution_message_id', 'A1')->value('id');
        $idB = IncomingMessage::where('evolution_message_id', 'B1')->value('id');

        // Contexto = conta A: a thread carrega SO mensagens de A (escopo por conta).
        app(AccountContext::class)->set($a->id);

        Livewire::test(Conversas::class)
            ->set('selectedJid', $jid)
            ->assertSeeHtml('/media/incoming/' . $idA . '?thumb=1')
            ->assertDontSeeHtml('/media/incoming/' . $idB . '?thumb=1');
    }
}
