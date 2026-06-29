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
 * Camada 4 (UI). Testes de componente Livewire. HTTP da Evolution mockado — SEM envio real.
 */
class UiTest extends TestCase
{
    use RefreshDatabase;

    private function account(): Account
    {
        return Account::create(['name' => 'Teste']);
    }

    public function test_conversas_envio_manual_chama_caminho_manual(): void
    {
        Http::fake(['*' => Http::response(['key' => ['id' => 'PMID']], 201)]);
        $account = $this->account();
        Channel::create(['account_id' => $account->id, 'instance' => 'fabio-pessoal', 'status' => 'connected']);
        AutoReplySetting::create(['account_id' => $account->id, 'min_interval_seconds' => 0]);

        Livewire::test(Conversas::class)
            ->set('selectedJid', '5541999990000@s.whatsapp.net')
            ->set('body', 'oi manual')
            ->call('sendManual');

        Http::assertSentCount(1);
        $this->assertDatabaseHas('auto_reply_logs', [
            'mode' => 'manual',
            'status' => 'sent',
            'remote_jid' => '5541999990000@s.whatsapp.net',
        ]);
    }

    public function test_conversas_aprovar_contato_seta_mode_on(): void
    {
        $this->account();

        Livewire::test(Conversas::class)
            ->set('selectedJid', '5541999990000@s.whatsapp.net')
            ->call('approveContact');

        $this->assertDatabaseHas('contacts', [
            'remote_jid' => '5541999990000@s.whatsapp.net',
            'auto_reply_mode' => 'on',
        ]);
    }

    public function test_regras_cria_alterna_e_apaga(): void
    {
        $this->account();

        $component = Livewire::test(Regras::class)
            ->call('novo')
            ->set('match_type', 'contains')
            ->set('match_value', 'horario')
            ->set('response_text', 'Atendo das 8h')
            ->call('save');

        $this->assertDatabaseHas('auto_reply_rules', ['match_value' => 'horario', 'enabled' => true]);

        $rule = AutoReplyRule::first();
        $component->call('toggle', $rule->id);
        $this->assertDatabaseHas('auto_reply_rules', ['id' => $rule->id, 'enabled' => false]);

        $component->call('delete', $rule->id);
        $this->assertDatabaseMissing('auto_reply_rules', ['id' => $rule->id]);
    }

    public function test_regras_reordena_priority(): void
    {
        $account = $this->account();
        $a = AutoReplyRule::create(['account_id' => $account->id, 'match_type' => 'contains', 'match_value' => 'a', 'response_text' => 'A', 'priority' => 0]);
        $b = AutoReplyRule::create(['account_id' => $account->id, 'match_type' => 'contains', 'match_value' => 'b', 'response_text' => 'B', 'priority' => 1]);

        Livewire::test(Regras::class)->call('move', $b->id, 'up');

        $this->assertSame(0, (int) $b->fresh()->priority);
        $this->assertSame(1, (int) $a->fresh()->priority);
    }

    public function test_contatos_set_mode(): void
    {
        $account = $this->account();
        $c = Contact::create(['account_id' => $account->id, 'remote_jid' => '5541999990000@s.whatsapp.net', 'auto_reply_mode' => 'default']);

        Livewire::test(Contatos::class)->call('setMode', $c->id, 'on');

        $this->assertDatabaseHas('contacts', ['id' => $c->id, 'auto_reply_mode' => 'on']);
    }

    public function test_configuracoes_kill_switch_flip_no_teste(): void
    {
        $account = $this->account();
        AutoReplySetting::create(['account_id' => $account->id, 'enabled' => false]);

        Livewire::test(Configuracoes::class)->call('toggleKillSwitch');

        $this->assertDatabaseHas('auto_reply_settings', ['account_id' => $account->id, 'enabled' => true]);
    }

    public function test_configuracoes_salva_freios(): void
    {
        $account = $this->account();
        AutoReplySetting::create(['account_id' => $account->id]);

        Livewire::test(Configuracoes::class)
            ->set('reply_policy', 'all')
            ->set('per_day_cap', 25)
            ->call('save');

        $this->assertDatabaseHas('auto_reply_settings', [
            'account_id' => $account->id,
            'reply_policy' => 'all',
            'per_day_cap' => 25,
        ]);
    }
}
