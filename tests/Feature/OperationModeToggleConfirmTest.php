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
 * Fatia 4b — confirmacao forte ao LIGAR o modo automatico. Desligar e imediato.
 *
 * Fatia 9 — o modal de ativacao passou a conter o SELECT de fluxos habilitados:
 * escolha obrigatoria (quando houver algum), posse validada SERVER-SIDE (id de
 * outra conta ou de fluxo desabilitado e rejeitado sem persistir), e Confirmar
 * grava default_flow_id + operation_mode=auto JUNTOS. A variante de AVISO agora
 * so existe quando a conta NAO tem nenhum fluxo habilitado (ativar mesmo assim
 * segue permitido — degradacao graciosa). Isolamento intacto.
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

    private function fluxoValido(Account $a, string $nome = 'Atendimento'): Flow
    {
        $flow = Flow::create(['account_id' => $a->id, 'name' => $nome, 'enabled' => true, 'timeout_seconds' => 600]);
        $root = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'menu', 'message' => 'MENU']);
        $flow->update(['root_node_id' => $root->id]);

        return $flow;
    }

    private function settingsDe(Account $a): AutoReplySetting
    {
        return AutoReplySetting::withoutAccountScope()->where('account_id', $a->id)->first();
    }

    private function modoDe(Account $a): OperationMode
    {
        return $this->settingsDe($a)->operation_mode;
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

        // CANCELAR: permanece Personal (e o default nao muda).
        $tela->call('cancelarAtivacao')->assertSet('confirming', false)->assertSet('auto', false);
        $this->assertSame(OperationMode::Personal, $this->modoDe($a));
        $this->assertSame($flow->id, $this->settingsDe($a)->default_flow_id);

        // CONFIRMAR (default valido veio pre-selecionado): persiste Auto.
        $tela->call('toggle')->assertSet('confirming', true)
            ->call('confirmarAtivacao')
            ->assertSet('confirming', false)->assertSet('auto', true);
        $this->assertSame(OperationMode::Auto, $this->modoDe($a));
    }

    public function test_modal_lista_so_fluxos_habilitados_da_conta_ativa(): void
    {
        $b = Account::create(['name' => 'B']);
        Flow::create(['account_id' => $b->id, 'name' => 'Fluxo-Da-B', 'enabled' => true, 'timeout_seconds' => 600]);

        $a = $this->conta('A');
        $this->fluxoValido($a, 'Fluxo-Ligado-A');
        Flow::create(['account_id' => $a->id, 'name' => 'Fluxo-Desligado-A', 'enabled' => false, 'timeout_seconds' => 600]);
        AutoReplySetting::create(['account_id' => $a->id]);

        Livewire::test(OperationModeToggle::class)
            ->call('toggle')
            ->assertSee('pelo fluxo selecionado')
            ->assertSee('Fluxo-Ligado-A')          // habilitado da conta ativa: listado
            ->assertDontSee('Fluxo-Desligado-A')   // desabilitado: fora do select
            ->assertDontSee('Fluxo-Da-B');         // de OUTRA conta: nunca aparece
    }

    public function test_preselecao_default_valido_vem_selecionado_e_invalido_vem_placeholder(): void
    {
        $a = $this->conta('A');
        $on = $this->fluxoValido($a, 'Ligado');
        AutoReplySetting::create(['account_id' => $a->id, 'default_flow_id' => $on->id]);

        // Default valido/habilitado: pre-selecionado.
        Livewire::test(OperationModeToggle::class)
            ->call('toggle')
            ->assertSet('fluxoEscolhido', $on->id);

        // Default apontando fluxo DESABILITADO (mas ha outro habilitado): placeholder.
        $off = Flow::create(['account_id' => $a->id, 'name' => 'Desligado', 'enabled' => false, 'timeout_seconds' => 600]);
        $this->settingsDe($a)->update(['default_flow_id' => $off->id]);

        Livewire::test(OperationModeToggle::class)
            ->call('toggle')
            ->assertSet('fluxoEscolhido', null)
            ->assertSee('Escolha um fluxo...');
    }

    public function test_confirmar_sem_selecao_e_rejeitado_server_side_sem_persistir(): void
    {
        $a = $this->conta('A');
        $this->fluxoValido($a); // ha fluxo habilitado -> escolha OBRIGATORIA
        AutoReplySetting::create(['account_id' => $a->id]); // default null

        Livewire::test(OperationModeToggle::class)
            ->call('toggle')
            ->assertSet('fluxoEscolhido', null)
            ->call('confirmarAtivacao')            // acao forjada sem escolher
            ->assertHasErrors('fluxoEscolhido')
            ->assertSet('confirming', true)        // modal segue aberto
            ->assertSet('auto', false);

        $this->assertSame(OperationMode::Personal, $this->modoDe($a)); // NADA persistiu
        $this->assertNull($this->settingsDe($a)->default_flow_id);
    }

    public function test_confirmar_com_selecao_grava_default_e_auto_juntos(): void
    {
        $a = $this->conta('A');
        $this->fluxoValido($a, 'Primeiro');
        $escolhido = $this->fluxoValido($a, 'Segundo');
        AutoReplySetting::create(['account_id' => $a->id]); // default null

        Livewire::test(OperationModeToggle::class)
            ->call('toggle')
            ->set('fluxoEscolhido', $escolhido->id)
            ->call('confirmarAtivacao')
            ->assertHasNoErrors()
            ->assertSet('confirming', false)
            ->assertSet('auto', true);

        $s = $this->settingsDe($a);
        $this->assertSame(OperationMode::Auto, $s->operation_mode);
        $this->assertSame($escolhido->id, $s->default_flow_id); // gravados JUNTOS
    }

    public function test_cancelar_apos_selecionar_nao_persiste_nem_modo_nem_fluxo(): void
    {
        $a = $this->conta('A');
        $flow = $this->fluxoValido($a);
        AutoReplySetting::create(['account_id' => $a->id]); // default null

        Livewire::test(OperationModeToggle::class)
            ->call('toggle')
            ->set('fluxoEscolhido', $flow->id)
            ->call('cancelarAtivacao')
            ->assertSet('confirming', false)
            ->assertSet('auto', false);

        $s = $this->settingsDe($a);
        $this->assertSame(OperationMode::Personal, $s->operation_mode);
        $this->assertNull($s->default_flow_id); // a selecao abandonada NAO vazou pro banco
    }

    public function test_posse_fluxo_de_outra_conta_ou_desabilitado_e_rejeitado(): void
    {
        $b = Account::create(['name' => 'B']);
        $fluxoB = Flow::create(['account_id' => $b->id, 'name' => 'Da-B', 'enabled' => true, 'timeout_seconds' => 600]);

        $a = $this->conta('A');
        $this->fluxoValido($a);
        $off = Flow::create(['account_id' => $a->id, 'name' => 'Off-A', 'enabled' => false, 'timeout_seconds' => 600]);
        AutoReplySetting::create(['account_id' => $a->id]);

        // (a) id de fluxo de OUTRA conta: rejeitado, nada persiste.
        Livewire::test(OperationModeToggle::class)
            ->call('toggle')
            ->set('fluxoEscolhido', $fluxoB->id)
            ->call('confirmarAtivacao')
            ->assertHasErrors('fluxoEscolhido')
            ->assertSet('auto', false);
        $this->assertSame(OperationMode::Personal, $this->modoDe($a));
        $this->assertNull($this->settingsDe($a)->default_flow_id);

        // (b) id de fluxo DESABILITADO da propria conta: mesmo tratamento.
        Livewire::test(OperationModeToggle::class)
            ->call('toggle')
            ->set('fluxoEscolhido', $off->id)
            ->call('confirmarAtivacao')
            ->assertHasErrors('fluxoEscolhido')
            ->assertSet('auto', false);
        $this->assertSame(OperationMode::Personal, $this->modoDe($a));
        $this->assertNull($this->settingsDe($a)->default_flow_id);
    }

    public function test_sem_nenhum_fluxo_habilitado_avisa_e_permite_ativar(): void
    {
        $a = $this->conta('A');
        // Unico fluxo da conta esta DESABILITADO -> variante de AVISO (sem select).
        Flow::create(['account_id' => $a->id, 'name' => 'Off', 'enabled' => false, 'timeout_seconds' => 600]);
        AutoReplySetting::create(['account_id' => $a->id, 'default_flow_id' => null]);

        $tela = Livewire::test(OperationModeToggle::class)
            ->call('toggle')
            ->assertSee('nao tem nenhum fluxo habilitado')
            ->assertSee('nao respondera nada')
            ->assertDontSee('Escolha um fluxo...'); // sem select nessa variante

        // Ativar mesmo assim e permitido (nao bloqueia): Auto com default null.
        $tela->call('confirmarAtivacao')->assertSet('auto', true);
        $this->assertSame(OperationMode::Auto, $this->modoDe($a));
        $this->assertNull($this->settingsDe($a)->default_flow_id);
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
        $fluxoA = Flow::create(['account_id' => $a->id, 'name' => 'F-A', 'enabled' => true, 'timeout_seconds' => 600]);
        AutoReplySetting::create(['account_id' => $a->id]); // Personal
        AutoReplySetting::create(['account_id' => $b->id]); // Personal

        app(AccountContext::class)->set($a->id);
        Livewire::test(OperationModeToggle::class)
            ->call('toggle')
            ->set('fluxoEscolhido', $fluxoA->id)
            ->call('confirmarAtivacao');

        $sA = AutoReplySetting::withoutAccountScope()->where('account_id', $a->id)->first();
        $sB = AutoReplySetting::withoutAccountScope()->where('account_id', $b->id)->first();
        $this->assertSame(OperationMode::Auto, $sA->operation_mode);
        $this->assertSame($fluxoA->id, $sA->default_flow_id);
        $this->assertSame(OperationMode::Personal, $sB->operation_mode); // B intacta
        $this->assertNull($sB->default_flow_id);                         // default de B intacto

        // Desligar em A tambem nao toca B.
        Livewire::test(OperationModeToggle::class)->call('toggle');
        $this->assertSame(OperationMode::Personal, $sA->fresh()->operation_mode);
        $this->assertSame(OperationMode::Personal, $sB->fresh()->operation_mode);
    }
}
