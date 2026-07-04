<?php

namespace Tests\Feature;

use App\Enums\OperationMode;
use App\Livewire\Configuracoes;
use App\Models\Account;
use App\Models\AutoReplySetting;
use App\Tenancy\AccountContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fatia 6 — tetos anti-ban editaveis + presets opt-in (Cloud/Evolution). So
 * VALORES: a logica de throttle/AntiBanGuard nao muda (suite existente prova).
 * Defaults inalterados; preset e opt-in explicito; isolamento por conta.
 */
class AntiBanPresetTest extends TestCase
{
    use RefreshDatabase;

    private function conta(string $nome = 'A'): Account
    {
        $a = Account::create(['name' => $nome]);
        app(AccountContext::class)->set($a->id);

        return $a;
    }

    private function tetosDe(Account $a): AutoReplySetting
    {
        return AutoReplySetting::withoutAccountScope()->where('account_id', $a->id)->first();
    }

    public function test_preset_cloud_grava_os_valores_aprovados(): void
    {
        $a = $this->conta();
        AutoReplySetting::create(['account_id' => $a->id]); // defaults conservadores

        Livewire::test(Configuracoes::class)->call('aplicarPreset', 'cloud');

        $s = $this->tetosDe($a);
        $this->assertSame(25, (int) $s->per_minute_cap);
        $this->assertSame(750, (int) $s->per_day_cap);
        $this->assertSame(1, (int) $s->min_interval_seconds);
        $this->assertSame(2, (int) $s->contact_rate_seconds);
        $this->assertFalse((bool) $s->warmup_enabled);
    }

    public function test_preset_evolution_grava_valores_conservadores(): void
    {
        $a = $this->conta();
        AutoReplySetting::create(['account_id' => $a->id]);

        Livewire::test(Configuracoes::class)->call('aplicarPreset', 'evolution');

        $s = $this->tetosDe($a);
        $this->assertSame(9, (int) $s->per_minute_cap);
        $this->assertSame(175, (int) $s->per_day_cap);
        $this->assertSame(3, (int) $s->min_interval_seconds);
        $this->assertSame(5, (int) $s->contact_rate_seconds);
        $this->assertTrue((bool) $s->warmup_enabled);
    }

    public function test_campos_seguem_editaveis_apos_preset_e_edicao_persiste(): void
    {
        $a = $this->conta();
        AutoReplySetting::create(['account_id' => $a->id]);

        // preset como ponto de partida; edicao manual por cima persiste
        Livewire::test(Configuracoes::class)
            ->call('aplicarPreset', 'cloud')
            ->set('per_day_cap', 300)
            ->call('save')->assertHasNoErrors();

        $this->assertSame(300, (int) $this->tetosDe($a)->per_day_cap);
        $this->assertSame(25, (int) $this->tetosDe($a)->per_minute_cap); // resto do preset mantido
    }

    public function test_validacao_rejeita_invalidos(): void
    {
        $a = $this->conta();
        AutoReplySetting::create(['account_id' => $a->id]);

        // zero/negativo
        Livewire::test(Configuracoes::class)->set('per_minute_cap', 0)->call('save')->assertHasErrors('per_minute_cap');
        // typo absurdo (acima do maximo de sanidade)
        Livewire::test(Configuracoes::class)->set('per_day_cap', 99999)->call('save')->assertHasErrors('per_day_cap');
        Livewire::test(Configuracoes::class)->set('per_minute_cap', 500)->call('save')->assertHasErrors('per_minute_cap');
        // janela invertida
        Livewire::test(Configuracoes::class)->set('window_start', '20:00')->set('window_end', '08:00')
            ->call('save')->assertHasErrors('window_end');

        // nada disso persistiu
        $s = $this->tetosDe($a);
        $this->assertSame(4, (int) $s->per_minute_cap);   // default intacto
        $this->assertSame(40, (int) $s->per_day_cap);      // default intacto
    }

    public function test_isolamento_preset_em_a_nao_altera_b(): void
    {
        $b = Account::create(['name' => 'B']);
        AutoReplySetting::create(['account_id' => $b->id]); // defaults (4/40)
        $a = $this->conta('A');
        AutoReplySetting::create(['account_id' => $a->id]);

        Livewire::test(Configuracoes::class)->call('aplicarPreset', 'cloud'); // contexto = A

        $this->assertSame(750, (int) $this->tetosDe($a)->per_day_cap); // A com preset
        $sb = $this->tetosDe($b);
        $this->assertSame(4, (int) $sb->per_minute_cap);  // B intacta (defaults)
        $this->assertSame(40, (int) $sb->per_day_cap);
    }

    public function test_defaults_inalterados_para_conta_nova(): void
    {
        $a = $this->conta();
        $s = AutoReplySetting::firstOrCreate(['account_id' => $a->id])->fresh();

        // Personal conservador de sempre — nenhum default mudou nesta fatia.
        $this->assertSame(4, (int) $s->per_minute_cap);
        $this->assertSame(40, (int) $s->per_day_cap);
        $this->assertSame(30, (int) $s->min_interval_seconds);
        $this->assertSame(1800, (int) $s->contact_rate_seconds);
        $this->assertFalse((bool) $s->warmup_enabled);
        $this->assertSame(OperationMode::Personal, $s->operation_mode);
    }

    public function test_hint_de_tetos_baixos_so_em_auto(): void
    {
        $a = $this->conta();
        AutoReplySetting::create(['account_id' => $a->id, 'operation_mode' => OperationMode::Auto]); // per_day default 40 (<=50)

        Livewire::test(Configuracoes::class)
            ->assertSee('Modo automatico ativo com tetos baixos');

        // em personal (mesmos tetos), o hint NAO aparece
        $this->tetosDe($a)->update(['operation_mode' => OperationMode::Personal]);
        Livewire::test(Configuracoes::class)
            ->assertDontSee('Modo automatico ativo com tetos baixos');
    }
}
