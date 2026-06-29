<?php

namespace Tests\Feature;

use App\Livewire\Configuracoes;
use App\Livewire\Contatos;
use App\Livewire\Conversas;
use App\Livewire\Regras;
use App\Models\Account;
use App\Models\AutoReplyRule;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Camada 4 (UI) — refino. Testes de componente Livewire, HTTP mockado. SEM envio real.
 * Cobre R28: modal abrir -> confirmar executa / cancelar nao executa.
 */
class UiTest extends TestCase
{
    use RefreshDatabase;

    private const JID = '5541999990000@s.whatsapp.net';

    private function account(): Account
    {
        return Account::create(['name' => 'Teste']);
    }

    // ---- Conversas ----------------------------------------------------------

    public function test_conversas_envio_manual_chama_caminho_manual(): void
    {
        Http::fake(['*' => Http::response(['key' => ['id' => 'PMID']], 201)]);
        $account = $this->account();
        Channel::create(['account_id' => $account->id, 'instance' => 'fabio-pessoal', 'status' => 'connected']);
        AutoReplySetting::create(['account_id' => $account->id, 'min_interval_seconds' => 0]);

        Livewire::test(Conversas::class)
            ->set('selectedJid', self::JID)
            ->set('body', 'oi manual')
            ->call('sendManual');

        Http::assertSentCount(1);
        $this->assertDatabaseHas('auto_reply_logs', ['mode' => 'manual', 'status' => 'sent', 'remote_jid' => self::JID]);
    }

    public function test_conversas_aprovar_seta_mode_on(): void
    {
        $this->account();

        Livewire::test(Conversas::class)->call('approveJid', self::JID);

        $this->assertDatabaseHas('contacts', ['remote_jid' => self::JID, 'auto_reply_mode' => 'on']);
    }

    public function test_conversas_silenciar_confirmar_seta_off(): void
    {
        $this->account();

        Livewire::test(Conversas::class)
            ->call('confirmMute', self::JID)
            ->assertSet('confirmingMuteJid', self::JID)
            ->call('muteConfirmed');

        $this->assertDatabaseHas('contacts', ['remote_jid' => self::JID, 'auto_reply_mode' => 'off']);
    }

    public function test_conversas_silenciar_cancelar_nao_altera(): void
    {
        $account = $this->account();
        Contact::create(['account_id' => $account->id, 'remote_jid' => self::JID, 'auto_reply_mode' => 'on']);

        Livewire::test(Conversas::class)
            ->call('confirmMute', self::JID)
            ->call('cancelMute')
            ->assertSet('confirmingMuteJid', null);

        $this->assertDatabaseHas('contacts', ['remote_jid' => self::JID, 'auto_reply_mode' => 'on']);
    }

    // ---- Regras -------------------------------------------------------------

    public function test_regras_cria_via_modal(): void
    {
        $this->account();

        Livewire::test(Regras::class)
            ->call('novo')
            ->assertSet('showForm', true)
            ->set('triggers.0.type', 'contains')
            ->set('triggers.0.value', 'horario')
            ->set('responses.0', 'Atendo das 8h')
            ->call('save')
            ->assertSet('showForm', false);

        // Coluna legada = cache do 1o gatilho/resposta; e as filhas foram criadas.
        $this->assertDatabaseHas('auto_reply_rules', ['match_value' => 'horario', 'enabled' => true]);
        $this->assertDatabaseHas('rule_triggers', ['match_type' => 'contains', 'match_value' => 'horario']);
        $this->assertDatabaseHas('rule_responses', ['response_text' => 'Atendo das 8h']);
    }

    public function test_regras_excluir_confirmar_apaga(): void
    {
        $account = $this->account();
        $rule = AutoReplyRule::create(['account_id' => $account->id, 'match_type' => 'contains', 'match_value' => 'x', 'response_text' => 'X']);

        $c = Livewire::test(Regras::class)->call('confirmDelete', $rule->id);
        $this->assertDatabaseHas('auto_reply_rules', ['id' => $rule->id]); // ainda nao apagou
        $c->call('deleteConfirmed');
        $this->assertDatabaseMissing('auto_reply_rules', ['id' => $rule->id]);
    }

    public function test_regras_excluir_cancelar_nao_apaga(): void
    {
        $account = $this->account();
        $rule = AutoReplyRule::create(['account_id' => $account->id, 'match_type' => 'contains', 'match_value' => 'x', 'response_text' => 'X']);

        Livewire::test(Regras::class)
            ->call('confirmDelete', $rule->id)
            ->call('cancelDelete')
            ->assertSet('confirmingDeleteId', null);

        $this->assertDatabaseHas('auto_reply_rules', ['id' => $rule->id]);
    }

    public function test_regras_toggle_e_reordena(): void
    {
        $account = $this->account();
        $a = AutoReplyRule::create(['account_id' => $account->id, 'match_type' => 'contains', 'match_value' => 'a', 'response_text' => 'A', 'priority' => 0, 'enabled' => true]);
        $b = AutoReplyRule::create(['account_id' => $account->id, 'match_type' => 'contains', 'match_value' => 'b', 'response_text' => 'B', 'priority' => 1]);

        $c = Livewire::test(Regras::class)->call('toggle', $a->id);
        $this->assertDatabaseHas('auto_reply_rules', ['id' => $a->id, 'enabled' => false]);

        $c->call('move', $b->id, 'up');
        $this->assertSame(0, (int) $b->fresh()->priority);
        $this->assertSame(1, (int) $a->fresh()->priority);
    }

    // ---- Contatos -----------------------------------------------------------

    public function test_contatos_set_mode_on(): void
    {
        $account = $this->account();
        $c = Contact::create(['account_id' => $account->id, 'remote_jid' => self::JID, 'auto_reply_mode' => 'default']);

        Livewire::test(Contatos::class)->call('setMode', $c->id, 'on');

        $this->assertDatabaseHas('contacts', ['id' => $c->id, 'auto_reply_mode' => 'on']);
    }

    public function test_contatos_silenciar_confirmar_seta_off(): void
    {
        $account = $this->account();
        $c = Contact::create(['account_id' => $account->id, 'remote_jid' => self::JID, 'auto_reply_mode' => 'on']);

        Livewire::test(Contatos::class)
            ->call('confirmMute', $c->id)
            ->call('muteConfirmed');

        $this->assertDatabaseHas('contacts', ['id' => $c->id, 'auto_reply_mode' => 'off']);
    }

    // ---- Configuracoes (kill switch) ---------------------------------------

    public function test_config_ligar_kill_switch_pede_confirmacao(): void
    {
        $account = $this->account();
        AutoReplySetting::create(['account_id' => $account->id, 'enabled' => false]);

        Livewire::test(Configuracoes::class)
            ->call('requestKillSwitch')
            ->assertSet('confirmingEnable', true);

        // Ainda NAO ligou: so abriu o modal.
        $this->assertDatabaseHas('auto_reply_settings', ['account_id' => $account->id, 'enabled' => false]);
    }

    public function test_config_confirmar_liga_kill_switch(): void
    {
        $account = $this->account();
        AutoReplySetting::create(['account_id' => $account->id, 'enabled' => false]);

        Livewire::test(Configuracoes::class)
            ->call('requestKillSwitch')
            ->call('enableConfirmed')
            ->assertSet('confirmingEnable', false);

        $this->assertDatabaseHas('auto_reply_settings', ['account_id' => $account->id, 'enabled' => true]);
    }

    public function test_config_desligar_kill_switch_instantaneo(): void
    {
        $account = $this->account();
        AutoReplySetting::create(['account_id' => $account->id, 'enabled' => true]);

        Livewire::test(Configuracoes::class)
            ->call('requestKillSwitch')
            ->assertSet('confirmingEnable', false);

        // Desligar e instantaneo, sem modal.
        $this->assertDatabaseHas('auto_reply_settings', ['account_id' => $account->id, 'enabled' => false]);
    }

    public function test_config_salva_freios(): void
    {
        $account = $this->account();
        AutoReplySetting::create(['account_id' => $account->id]);

        Livewire::test(Configuracoes::class)
            ->set('reply_policy', 'all')
            ->set('per_day_cap', 25)
            ->call('save');

        $this->assertDatabaseHas('auto_reply_settings', ['account_id' => $account->id, 'reply_policy' => 'all', 'per_day_cap' => 25]);
    }
}
