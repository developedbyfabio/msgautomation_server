<?php

namespace Tests\Feature;

use App\Livewire\Conversas;
use App\Models\Account;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\IncomingMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 03 — composer do /conversas. A parte de TECLA/altura e front (Alpine,
 * checklist manual no relatorio); aqui prova-se o que e testavel no back:
 * emoji e quebras de linha sobrevivem ida e volta (persistencia + POST pro
 * canal) e o sendManual continua IDENTICO (so mudou como dispara).
 */
class ConversasInputTest extends TestCase
{
    use RefreshDatabase;

    private const JID = '5541999990000@s.whatsapp.net';

    private function account(): Account
    {
        $account = Account::create(['name' => 'Teste']);
        Channel::create(['account_id' => $account->id, 'instance' => 'fabio-pessoal', 'status' => 'connected']);
        AutoReplySetting::create(['account_id' => $account->id, 'min_interval_seconds' => 0]);

        return $account;
    }

    public function test_emoji_e_quebra_de_linha_sobrevivem_no_envio_manual(): void
    {
        Http::fake(['*' => Http::response(['key' => ['id' => 'PMID']], 201)]);
        $this->account();

        $texto = "Oi! 😀🔥❤️\nSegunda linha 🎉\nTerceira ✅";

        Livewire::test(Conversas::class)
            ->set('selectedJid', self::JID)
            ->set('body', $texto)
            ->call('sendManual');

        // Persistiu EXATAMENTE igual (emoji multi-byte + \n intactos).
        $log = \App\Models\AutoReplyLog::query()->where('mode', 'manual')->firstOrFail();
        $this->assertSame($texto, $log->response_text);
        $this->assertSame('sent', $log->status);

        // E o POST pro canal levou o mesmo texto.
        Http::assertSent(fn ($request) => ($request->data()['text'] ?? null) === $texto);
    }

    public function test_emoji_recebido_persiste_e_aparece_na_thread(): void
    {
        $account = $this->account();

        IncomingMessage::create([
            'account_id' => $account->id,
            'instance' => 'fabio-pessoal',
            'evolution_message_id' => 'MSG-EMOJI-1',
            'remote_jid' => self::JID,
            'from_me' => false,
            'push_name' => 'Cliente',
            'type' => 'conversation',
            'text' => 'Fechado! 🤝🚀 Até amanhã 🙏',
            'raw_payload' => [],
            'received_at' => now(),
        ]);

        // Ida e volta do banco: le igual ao que salvou.
        $this->assertSame('Fechado! 🤝🚀 Até amanhã 🙏', IncomingMessage::query()->firstOrFail()->text);

        // E a tela renderiza o emoji (thread da conversa).
        Livewire::test(Conversas::class)
            ->set('selectedJid', self::JID)
            ->assertSee('Fechado! 🤝🚀 Até amanhã 🙏');
    }
}
