<?php

namespace Tests\Feature;

use App\Enums\OperationMode;
use App\Livewire\OperationModeToggle;
use App\Models\Account;
use App\Models\AutoReplySetting;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Tenancy\AccountContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fatia 4b — confirmacao forte ao LIGAR o modo automatico (agora que ele muda o
 * comportamento de verdade). Desligar e imediato. Copy dinamica: variante de
 * AVISO quando nao ha fluxo padrao valido/habilitado. Isolamento intacto.
 */
class OperationModeToggleConfirmTest extends TestCase
{
    use RefreshDatabase;

    private function conta(string $nome): Account
    {
        $a = Account::create(['name' => $nome]);
        app(AccountContext::class)->set($a->id);

        return $a;
    }

    private function fluxoValido(Account $a): Flow
    {
        $flow = Flow::create(['account_id' => $a->id, 'name' => 'Atendimento', 'enabled' => true, 'timeout_seconds' => 600]);
        $root = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'menu', 'message' => 'MENU']);
        $flow->update(['root_node_id' => $root->id]);

        return $flow;
    }

    private function modoDe(Account $a): OperationMode
    {
        return AutoReplySetting::withoutAccountScope()->where('account_id', $a->id)->first()->operation_mode;
    }

    public function test_ligar_exige_confirmacao_confirmar_persiste_cancelar_mantem(): void
    {
        $a = $this->conta('A');
        $flow = $this->fluxoValido($a);
        AutoReplySetting::create(['account_id' => $a->id, 'default_flow_id' => $flow->id]); // Personal

        // Clique no toggle NAO persiste ainda: abre a confirmacao.
        $tela = Livewire::test(OperationModeToggle::class)
            ->call('toggle')
            ->assertSet('confirming', true)
            ->assertSet('auto', false);
        $this->assertSame(OperationMode::Personal, $this->modoDe($a)); // nada persistido

        // CANCELAR: permanece Personal.
        $tela->call('cancelarAtivacao')->assertSet('confirming', false)->assertSet('auto', false);
        $this->assertSame(OperationMode::Personal, $this->modoDe($a));

        // CONFIRMAR: persiste Auto.
        $tela->call('toggle')->assertSet('confirming', true)
            ->call('confirmarAtivacao')
            ->assertSet('confirming', false)->assertSet('auto', true);
        $this->assertSame(OperationMode::Auto, $this->modoDe($a));
    }

    public function test_copy_padrao_quando_ha_fluxo_valido(): void
    {
        $a = $this->conta('A');
        $flow = $this->fluxoValido($a);
        AutoReplySetting::create(['account_id' => $a->id, 'default_flow_id' => $flow->id]);

        Livewire::test(OperationModeToggle::class)
            ->call('toggle')
            ->assertSet('temFluxoValido', true)
            ->assertSee('toda mensagem recebida')
            ->assertDontSee('Nenhum fluxo de atendimento padrao');
    }

    public function test_aviso_quando_nao_ha_fluxo_padrao(): void
    {
        $a = $this->conta('A');
        AutoReplySetting::create(['account_id' => $a->id, 'default_flow_id' => null]);

        $tela = Livewire::test(OperationModeToggle::class)
            ->call('toggle')
            ->assertSet('temFluxoValido', false)             // variante de AVISO
            ->assertSee('Nenhum fluxo de atendimento padrao')
            ->assertSee('nao respondera nada');

        // Ativar mesmo assim e permitido (nao bloqueia): persiste Auto.
        $tela->call('confirmarAtivacao')->assertSet('auto', true);
        $this->assertSame(OperationMode::Auto, $this->modoDe($a));
    }

    public function test_aviso_quando_fluxo_padrao_esta_desabilitado(): void
    {
        $a = $this->conta('A');
        $flow = $this->fluxoValido($a);
        $flow->update(['enabled' => false]); // desabilitado DEPOIS de escolhido
        AutoReplySetting::create(['account_id' => $a->id, 'default_flow_id' => $flow->id]);

        Livewire::test(OperationModeToggle::class)
            ->call('toggle')
            ->assertSet('temFluxoValido', false) // estado REAL no momento do clique
            ->assertSee('Nenhum fluxo de atendimento padrao');
    }

    public function test_desligar_e_imediato_sem_confirmacao(): void
    {
        $a = $this->conta('A');
        AutoReplySetting::create(['account_id' => $a->id, 'operation_mode' => OperationMode::Auto]);

        Livewire::test(OperationModeToggle::class)
            ->assertSet('auto', true)
            ->call('toggle')                       // UM clique
            ->assertSet('confirming', false)       // sem modal
            ->assertSet('auto', false);
        $this->assertSame(OperationMode::Personal, $this->modoDe($a)); // persistiu direto
    }

    public function test_isolamento_confirmar_em_a_nao_altera_b(): void
    {
        $a = Account::create(['name' => 'A']);
        $b = Account::create(['name' => 'B']);
        AutoReplySetting::create(['account_id' => $a->id]); // Personal
        AutoReplySetting::create(['account_id' => $b->id]); // Personal

        app(AccountContext::class)->set($a->id);
        Livewire::test(OperationModeToggle::class)->call('toggle')->call('confirmarAtivacao');

        $this->assertSame(OperationMode::Auto, $this->modoDe($a));
        $this->assertSame(OperationMode::Personal, $this->modoDe($b)); // B intacta

        // Desligar em A tambem nao toca B.
        Livewire::test(OperationModeToggle::class)->call('toggle');
        $this->assertSame(OperationMode::Personal, $this->modoDe($a));
        $this->assertSame(OperationMode::Personal, $this->modoDe($b));
    }
}
