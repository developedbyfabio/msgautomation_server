<?php

namespace Tests\Feature;

use App\Livewire\Conversas;
use App\Models\Account;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\IncomingMessage;
use App\Whatsapp\MessagePreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * S5 — preview por tipo: icone/label/legenda/emoji em vez de [imageMessage] cru.
 */
class MessagePreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_helper_deriva_label_legenda_e_emoji(): void
    {
        $img = MessagePreview::for('imageMessage', 'minha legenda', ['data' => ['message' => ['imageMessage' => ['caption' => 'minha legenda']]]]);
        $this->assertSame('Imagem', $img['label']);
        $this->assertSame('photo', $img['icon']);
        $this->assertSame('minha legenda', $img['caption']);

        $doc = MessagePreview::for('documentMessage', null, ['data' => ['message' => ['documentMessage' => ['fileName' => 'nota.pdf']]]]);
        $this->assertSame('nota.pdf', $doc['label']);

        $reac = MessagePreview::for('reactionMessage', null, ['data' => ['message' => ['reactionMessage' => ['text' => '😂']]]]);
        $this->assertSame('😂', $reac['emoji']);

        $txt = MessagePreview::for('conversation', 'oi', []);
        $this->assertSame('oi', $txt['plain']);

        $desc = MessagePreview::for('fooBarMessage', null, []);
        $this->assertSame('[fooBarMessage]', $desc['label']); // fallback
    }

    public function test_thread_mostra_label_e_emoji(): void
    {
        $account = Account::create(['name' => 'Teste']);
        $channel = Channel::create(['account_id' => $account->id, 'instance' => 'fabio-pessoal', 'status' => 'connected']);
        $jid = '5541999990000@s.whatsapp.net';
        Contact::create(['account_id' => $account->id, 'remote_jid' => $jid, 'auto_reply_mode' => 'default']);

        $base = ['account_id' => $account->id, 'channel_id' => $channel->id, 'instance' => 'fabio-pessoal',
            'remote_jid' => $jid, 'from_me' => false, 'received_at' => Carbon::create(2026, 6, 29, 13, 0, 0, 'UTC')];

        IncomingMessage::create($base + ['evolution_message_id' => 'I1', 'type' => 'imageMessage', 'text' => 'olha isso',
            'raw_payload' => ['data' => ['message' => ['imageMessage' => ['caption' => 'olha isso']]]]]);
        IncomingMessage::create($base + ['evolution_message_id' => 'R1', 'type' => 'reactionMessage', 'text' => null,
            'raw_payload' => ['data' => ['message' => ['reactionMessage' => ['text' => '👍']]]]]);

        Livewire::test(Conversas::class)
            ->set('selectedJid', $jid)
            ->assertSee('Imagem')
            ->assertSee('olha isso')   // legenda
            ->assertSee('👍')          // emoji da reacao
            ->assertDontSee('[imageMessage]')
            ->assertDontSee('[reactionMessage]');
    }
}
