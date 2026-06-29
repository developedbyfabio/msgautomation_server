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
 * S4 — painel de info do contato (abrir, salvar nome/notas, toggle, midias-lista).
 */
class ContactPanelTest extends TestCase
{
    use RefreshDatabase;

    private const JID = '5541999990000@s.whatsapp.net';

    private function account(): Account
    {
        return Account::create(['name' => 'Teste']);
    }

    public function test_abrir_painel_carrega_nome_e_notas(): void
    {
        $account = $this->account();
        Contact::create([
            'account_id' => $account->id,
            'remote_jid' => self::JID,
            'push_name' => 'Joao',
            'notes' => 'cliente vip',
            'auto_reply_mode' => 'default',
        ]);

        Livewire::test(Conversas::class)
            ->set('selectedJid', self::JID)
            ->call('openContactPanel')
            ->assertSet('showContactPanel', true)
            ->assertSet('panelName', 'Joao')
            ->assertSet('panelNotes', 'cliente vip');
    }

    public function test_painel_nao_abre_para_grupo(): void
    {
        $this->account();

        Livewire::test(Conversas::class)
            ->set('selectedJid', '123@g.us')
            ->call('openContactPanel')
            ->assertSet('showContactPanel', false);
    }

    public function test_salvar_contato_grava_nome_notas_e_flag_saved(): void
    {
        $this->account();

        Livewire::test(Conversas::class)
            ->set('selectedJid', self::JID)
            ->set('panelName', 'Maria')
            ->set('panelNotes', 'fornecedora')
            ->call('saveContact');

        $this->assertDatabaseHas('contacts', [
            'remote_jid' => self::JID,
            'push_name' => 'Maria',
            'notes' => 'fornecedora',
            'saved' => true,
        ]);
    }

    public function test_set_selected_mode_on(): void
    {
        $this->account();

        Livewire::test(Conversas::class)
            ->set('selectedJid', self::JID)
            ->call('setSelectedMode', 'on');

        $this->assertDatabaseHas('contacts', ['remote_jid' => self::JID, 'auto_reply_mode' => 'on']);
    }

    public function test_midias_recentes_listadas(): void
    {
        $account = $this->account();
        $channel = Channel::create(['account_id' => $account->id, 'instance' => 'fabio-pessoal', 'status' => 'connected']);

        IncomingMessage::create([
            'account_id' => $account->id,
            'channel_id' => $channel->id,
            'instance' => 'fabio-pessoal',
            'evolution_message_id' => 'IMG1',
            'remote_jid' => self::JID,
            'from_me' => false,
            'type' => 'imageMessage',
            'text' => null,
            'raw_payload' => ['x' => 1],
            'received_at' => Carbon::create(2026, 6, 29, 13, 0, 0, 'UTC'),
        ]);

        Livewire::test(Conversas::class)
            ->set('selectedJid', self::JID)
            ->call('openContactPanel')
            ->assertSee('Midias recentes')
            ->assertSee('Imagem')
            ->assertSee('10:00'); // 13:00 UTC -> 10:00 SP
    }
}
