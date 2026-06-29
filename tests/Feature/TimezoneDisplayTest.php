<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\IncomingMessage;
use App\Livewire\Conversas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * S1 — fuso. Armazenamento em UTC; EXIBICAO convertida para America/Sao_Paulo (UTC-3).
 * O bug era "+3h" = UTC vazando na tela. Aqui travamos a conversao de exibicao.
 */
class TimezoneDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_storage_continua_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('America/Sao_Paulo', config('app.display_timezone'));
    }

    public function test_macro_converte_utc_para_sao_paulo(): void
    {
        // 13:46:45 UTC equivale a 10:46:45 em Sao Paulo (UTC-3, sem horario de verao).
        $utc = Carbon::create(2026, 6, 29, 13, 46, 45, 'UTC');

        $exibe = $utc->paraExibicao();

        $this->assertSame('America/Sao_Paulo', $exibe->timezone->getName());
        $this->assertSame('10:46:45', $exibe->format('H:i:s'));
        // O instante e o mesmo: nao perdemos/ganhamos tempo, so reapresentamos.
        $this->assertTrue($utc->equalTo($exibe));
    }

    public function test_thread_exibe_horario_local_nao_utc(): void
    {
        $account = Account::create(['name' => 'Teste']);
        $channel = Channel::create(['account_id' => $account->id, 'instance' => 'fabio-pessoal', 'status' => 'connected']);
        $jid = '5541999990000@s.whatsapp.net';
        Contact::create(['account_id' => $account->id, 'remote_jid' => $jid, 'auto_reply_mode' => 'default']);

        // received_at gravado em UTC (como o messageTimestamp epoch da Evolution).
        IncomingMessage::create([
            'account_id' => $account->id,
            'channel_id' => $channel->id,
            'instance' => 'fabio-pessoal',
            'evolution_message_id' => 'EVO-TZ-1',
            'remote_jid' => $jid,
            'from_me' => false,
            'type' => 'conversation',
            'text' => 'mensagem de horario conhecido',
            'raw_payload' => ['x' => 1],
            'received_at' => Carbon::create(2026, 6, 29, 13, 46, 45, 'UTC'),
        ]);

        Livewire::test(Conversas::class)
            ->set('selectedJid', $jid)
            ->assertSee('10:46')   // Sao Paulo
            ->assertDontSee('13:46'); // nao vaza UTC
    }
}
