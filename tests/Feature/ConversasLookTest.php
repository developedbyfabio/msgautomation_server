<?php

namespace Tests\Feature;

use App\Livewire\Conversas;
use App\Models\Account;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\IncomingMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * S6 — cara de WhatsApp: busca de conversas, hora relativa, separadores de data.
 */
class ConversasLookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // "Agora" fixo: 29/06/2026 12:00 em Sao Paulo.
        Carbon::setTestNow(Carbon::create(2026, 6, 29, 12, 0, 0, 'America/Sao_Paulo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function scaffold(): array
    {
        $account = Account::create(['name' => 'Teste']);
        $channel = Channel::create(['account_id' => $account->id, 'instance' => 'fabio-pessoal', 'status' => 'connected']);

        return [$account, $channel];
    }

    private function msg(Account $a, Channel $c, string $jid, string $text, Carbon $at, string $id): void
    {
        IncomingMessage::create([
            'account_id' => $a->id,
            'channel_id' => $c->id,
            'instance' => 'fabio-pessoal',
            'evolution_message_id' => $id,
            'remote_jid' => $jid,
            'from_me' => false,
            'type' => 'conversation',
            'text' => $text,
            'raw_payload' => ['x' => 1],
            'received_at' => $at,
        ]);
    }

    public function test_busca_filtra_conversas_por_nome(): void
    {
        [$a, $c] = $this->scaffold();
        $jidA = '5541111110000@s.whatsapp.net';
        $jidB = '5542222220000@s.whatsapp.net';
        Contact::create(['account_id' => $a->id, 'remote_jid' => $jidA, 'push_name' => 'Alpha']);
        Contact::create(['account_id' => $a->id, 'remote_jid' => $jidB, 'push_name' => 'Beta']);
        $this->msg($a, $c, $jidA, 'oi do alpha', now()->subHour(), 'A1');
        $this->msg($a, $c, $jidB, 'oi do beta', now()->subHour(), 'B1');

        Livewire::test(Conversas::class)
            ->assertSee('Alpha')
            ->assertSee('Beta')
            ->set('search', 'alph')
            ->assertSee('Alpha')
            ->assertDontSee('Beta');
    }

    public function test_thread_mostra_separadores_hoje_e_ontem(): void
    {
        [$a, $c] = $this->scaffold();
        $jid = '5541999990000@s.whatsapp.net';
        Contact::create(['account_id' => $a->id, 'remote_jid' => $jid, 'push_name' => 'Joao']);

        // 28/06 (ontem) e 29/06 (hoje), em horario de Sao Paulo.
        $this->msg($a, $c, $jid, 'mensagem de ontem', Carbon::create(2026, 6, 28, 13, 0, 0, 'UTC'), 'ONTEM');
        $this->msg($a, $c, $jid, 'mensagem de hoje', Carbon::create(2026, 6, 29, 13, 0, 0, 'UTC'), 'HOJE');

        Livewire::test(Conversas::class)
            ->set('selectedJid', $jid)
            ->assertSee('Ontem')
            ->assertSee('Hoje')
            ->assertSee('mensagem de ontem')
            ->assertSee('mensagem de hoje');
    }

    public function test_hora_relativa_na_lista(): void
    {
        [$a, $c] = $this->scaffold();
        $jid = '5541999990000@s.whatsapp.net';
        Contact::create(['account_id' => $a->id, 'remote_jid' => $jid, 'push_name' => 'Joao']);

        // Hoje 10:00 SP (13:00 UTC) -> lista mostra a hora, nao a data.
        $this->msg($a, $c, $jid, 'oi', Carbon::create(2026, 6, 29, 13, 0, 0, 'UTC'), 'H1');

        Livewire::test(Conversas::class)->assertSee('10:00');
    }
}
