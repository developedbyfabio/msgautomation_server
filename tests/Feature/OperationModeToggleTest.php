<?php

namespace Tests\Feature;

use App\Enums\OperationMode;
use App\Livewire\OperationModeToggle;
use App\Models\Account;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\User;
use App\Tenancy\AccountContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fatia 2 — toggle do Modo de Operacao (Livewire, per-account). Persiste
 * server-side na conta ATIVA; alternar A NUNCA afeta B. Pipeline segue INERTE
 * (nenhum ponto do robo le a flag nesta fatia).
 */
class OperationModeToggleTest extends TestCase
{
    use RefreshDatabase;

    private function conta(string $nome): Account
    {
        $a = Account::create(['name' => $nome]);
        app(AccountContext::class)->set($a->id);

        return $a;
    }

    public function test_toggle_liga_auto_e_desliga_persistindo_na_conta(): void
    {
        $a = $this->conta('A');
        AutoReplySetting::create(['account_id' => $a->id]); // nasce Personal (default)

        $tela = Livewire::test(OperationModeToggle::class)->assertSet('auto', false);

        // liga: Personal -> Auto (persistido)
        $tela->call('toggle')->assertSet('auto', true);
        $this->assertSame(
            OperationMode::Auto,
            AutoReplySetting::withoutAccountScope()->where('account_id', $a->id)->first()->operation_mode,
        );

        // desliga: Auto -> Personal (persistido)
        $tela->call('toggle')->assertSet('auto', false);
        $this->assertSame(
            OperationMode::Personal,
            AutoReplySetting::withoutAccountScope()->where('account_id', $a->id)->first()->operation_mode,
        );
    }

    public function test_isolamento_alternar_a_nao_afeta_b(): void
    {
        $a = Account::create(['name' => 'A']);
        $b = Account::create(['name' => 'B']);
        AutoReplySetting::create(['account_id' => $a->id, 'operation_mode' => OperationMode::Personal]);
        AutoReplySetting::create(['account_id' => $b->id, 'operation_mode' => OperationMode::Personal]);

        // Contexto = conta A (o que o SetAccountContext daria pro usuario de A).
        app(AccountContext::class)->set($a->id);
        Livewire::test(OperationModeToggle::class)->call('toggle')->assertSet('auto', true);

        // A virou Auto; B PERMANECE Personal (nunca tocada).
        $this->assertSame(OperationMode::Auto, AutoReplySetting::withoutAccountScope()->where('account_id', $a->id)->first()->operation_mode);
        $this->assertSame(OperationMode::Personal, AutoReplySetting::withoutAccountScope()->where('account_id', $b->id)->first()->operation_mode);
        $this->assertSame(1, AutoReplySetting::withoutAccountScope()->where('account_id', $b->id)->count()); // sem linha extra em B
    }

    public function test_conta_sem_settings_cria_escopado_e_alterna(): void
    {
        $b = Account::create(['name' => 'B']); // outra conta, sem settings
        $a = $this->conta('A');                // conta ativa, SEM linha de settings

        $this->assertSame(0, AutoReplySetting::withoutAccountScope()->where('account_id', $a->id)->count());

        Livewire::test(OperationModeToggle::class)
            ->assertSet('auto', false) // mount criou via firstOrCreate (default Personal)
            ->call('toggle')->assertSet('auto', true);

        // Criou/alterou SO a linha da conta ativa; B segue sem settings.
        $this->assertSame(OperationMode::Auto, AutoReplySetting::withoutAccountScope()->where('account_id', $a->id)->first()->operation_mode);
        $this->assertSame(0, AutoReplySetting::withoutAccountScope()->where('account_id', $b->id)->count());
    }

    public function test_smoke_render_no_header_reflete_o_banco(): void
    {
        // Full page (passa pelo middleware real): conta onboarded + modo Auto no banco.
        $a = Account::create(['name' => 'T']);
        Channel::create(['account_id' => $a->id, 'instance' => 'conta-' . $a->id . '-t', 'provider' => 'evolution',
            'webhook_token' => 'tok', 'status' => 'connected']);
        AutoReplySetting::create(['account_id' => $a->id, 'operation_mode' => OperationMode::Auto]);
        $u = User::create(['name' => 'Op', 'email' => 'op@x.local', 'password' => Hash::make('senha-forte-123')]);
        $u->accounts()->attach($a->id, ['role' => 'owner']);

        $this->actingAs($u)->withSession(['tenancy.account_id' => $a->id])
            ->get('/perfil')
            ->assertOk()
            ->assertSee('Alternar modo de operacao') // toggle presente no header
            ->assertSee('Automatico');               // estado inicial = valor do banco
    }
}
