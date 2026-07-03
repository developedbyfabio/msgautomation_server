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

    private function dataUri(array $bytes): string
    {
        return 'data:image/jpeg;base64,' . base64_encode(implode('', array_map('chr', $bytes)));
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

    public function test_imagem_com_thumbnail_renderiza_miniatura_data_uri(): void
    {
        $a = Account::create(['name' => 'A']);
        $c = Channel::create(['account_id' => $a->id, 'instance' => 'fabio-pessoal', 'status' => 'connected']);
        $jid = '5541999990000@s.whatsapp.net';
        Contact::create(['account_id' => $a->id, 'remote_jid' => $jid, 'auto_reply_mode' => 'default']);

        $bytes = $this->jpegBytes(0x41);
        $this->imagem($a, $c, $jid, 'IMG1', $bytes, 'olha essa foto');

        Livewire::test(Conversas::class)
            ->set('selectedJid', $jid)
            ->assertSeeHtml('src="' . $this->dataUri($bytes) . '"') // miniatura embutida
            ->assertSee('Previa (imagem completa em breve)')         // indicador de preview
            ->assertSee('olha essa foto');                            // legenda preservada
    }

    public function test_imagem_sem_thumbnail_cai_no_rotulo(): void
    {
        $a = Account::create(['name' => 'A']);
        $c = Channel::create(['account_id' => $a->id, 'instance' => 'fabio-pessoal', 'status' => 'connected']);
        $jid = '5541999990000@s.whatsapp.net';
        Contact::create(['account_id' => $a->id, 'remote_jid' => $jid, 'auto_reply_mode' => 'default']);

        $this->imagem($a, $c, $jid, 'IMG2', null, 'sem thumb');

        Livewire::test(Conversas::class)
            ->set('selectedJid', $jid)
            ->assertSee('Imagem')                       // rotulo padrao (nao quebra)
            ->assertDontSee('data:image/jpeg;base64,'); // nada de miniatura
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

        $bytesA = $this->jpegBytes(0x41); // "AA"
        $bytesB = $this->jpegBytes(0x42); // "BB"
        $this->imagem($a, $c = $ca, $jid, 'A1', $bytesA);
        $this->imagem($b, $c = $cb, $jid, 'B1', $bytesB);

        // Contexto = conta A: ve so a miniatura de A, nunca a de B.
        app(AccountContext::class)->set($a->id);

        Livewire::test(Conversas::class)
            ->set('selectedJid', $jid)
            ->assertSeeHtml('src="' . $this->dataUri($bytesA) . '"')
            ->assertDontSeeHtml('src="' . $this->dataUri($bytesB) . '"');
    }
}
