<?php

namespace Tests\Feature;

use App\Livewire\Fluxos;
use App\Models\Account;
use App\Models\AutoReplySetting;
use App\Models\Flow;
use App\Tenancy\AccountContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fatia 9 — badge "Padrao" na listagem de Fluxos: torna permanentemente visivel
 * a diferenca entre fluxo HABILITADO (enabled) e fluxo PADRAO (default_flow_id
 * da conta). Estado quebrado (padrao desabilitado) e sinalizado em tom de
 * atencao. Leitura apenas — a escrita segue em Configuracoes e no modal.
 *
 * Nota: nomes de fluxo dos cenarios NUNCA contem "Padrao" — as assercoes de
 * contagem/ausencia valem sobre o badge, nao sobre nomes.
 */
class FluxosDefaultBadgeTest extends TestCase
{
    use RefreshDatabase;

    private function conta(string $nome): Account
    {
        $a = Account::create(['name' => $nome]);
        app(AccountContext::class)->set($a->id);

        return $a;
    }

    public function test_badge_padrao_aparece_so_no_fluxo_default(): void
    {
        $a = $this->conta('A');
        $padrao = Flow::create(['account_id' => $a->id, 'name' => 'Fluxo-Um', 'enabled' => true, 'timeout_seconds' => 600]);
        Flow::create(['account_id' => $a->id, 'name' => 'Fluxo-Dois', 'enabled' => true, 'timeout_seconds' => 600]);
        AutoReplySetting::create(['account_id' => $a->id, 'default_flow_id' => $padrao->id]);

        $html = Livewire::test(Fluxos::class)->assertSee('Padrao')->html();

        // Exatamente UM badge (o do default) — o outro fluxo habilitado nao ganha.
        $this->assertSame(1, substr_count($html, 'Padrao'));
        $this->assertStringNotContainsString('Padrao (desabilitado)', $html);
    }

    public function test_badge_indica_quando_o_padrao_esta_desabilitado(): void
    {
        $a = $this->conta('A');
        $padrao = Flow::create(['account_id' => $a->id, 'name' => 'Fluxo-Um', 'enabled' => true, 'timeout_seconds' => 600]);
        AutoReplySetting::create(['account_id' => $a->id, 'default_flow_id' => $padrao->id]);
        $padrao->update(['enabled' => false]); // desabilitado DEPOIS de escolhido

        Livewire::test(Fluxos::class)->assertSee('Padrao (desabilitado)');
    }

    public function test_sem_default_nenhum_badge(): void
    {
        $a = $this->conta('A');
        Flow::create(['account_id' => $a->id, 'name' => 'Habilitado-Sem-Default', 'enabled' => true, 'timeout_seconds' => 600]);
        AutoReplySetting::create(['account_id' => $a->id, 'default_flow_id' => null]);

        $html = Livewire::test(Fluxos::class)->assertSee('Habilitado-Sem-Default')->html();
        $this->assertStringNotContainsString('Padrao', $html);
    }

    public function test_isolamento_default_de_outra_conta_nao_gera_badge(): void
    {
        $b = Account::create(['name' => 'B']);
        $fluxoB = Flow::create(['account_id' => $b->id, 'name' => 'Da-B', 'enabled' => true, 'timeout_seconds' => 600]);
        AutoReplySetting::create(['account_id' => $b->id, 'default_flow_id' => $fluxoB->id]);

        $a = $this->conta('A');
        Flow::create(['account_id' => $a->id, 'name' => 'Da-A', 'enabled' => true, 'timeout_seconds' => 600]);
        AutoReplySetting::create(['account_id' => $a->id, 'default_flow_id' => null]);

        // A listagem de A le SO as settings de A: nenhum badge.
        $html = Livewire::test(Fluxos::class)->assertSee('Da-A')->assertDontSee('Da-B')->html();
        $this->assertStringNotContainsString('Padrao', $html);
    }
}
