<?php

namespace Tests\Feature;

use App\Livewire\Configuracoes;
use App\Models\Account;
use App\Models\AutoReplySetting;
use App\Models\Flow;
use App\Tenancy\AccountContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fatia 3 — selecao do fluxo de atendimento padrao (Configuracoes). Persiste
 * default_flow_id da conta ativa com VALIDACAO server-side de posse (fluxo de
 * outra conta e rejeitado). Pipeline segue INERTE (robo nao le a flag).
 */
class DefaultFlowSelectionTest extends TestCase
{
    use RefreshDatabase;

    private function conta(string $nome): Account
    {
        $a = Account::create(['name' => $nome]);
        app(AccountContext::class)->set($a->id);

        return $a;
    }

    private function fluxo(Account $a, string $nome, bool $enabled = true): Flow
    {
        return Flow::create([
            'account_id' => $a->id, 'name' => $nome, 'enabled' => $enabled,
            'scope' => 'global', 'timeout_seconds' => 300,
        ]);
    }

    public function test_persiste_fluxo_habilitado_e_nenhum_grava_null(): void
    {
        $a = $this->conta('A');
        $flow = $this->fluxo($a, 'Atendimento');
        AutoReplySetting::create(['account_id' => $a->id]);

        // seleciona o fluxo habilitado -> persiste
        Livewire::test(Configuracoes::class)
            ->set('default_flow_id', $flow->id)
            ->call('save')->assertHasNoErrors();
        $this->assertSame($flow->id, AutoReplySetting::withoutAccountScope()->where('account_id', $a->id)->first()->default_flow_id);

        // 'Nenhum' -> null
        Livewire::test(Configuracoes::class)
            ->set('default_flow_id', null)
            ->call('save')->assertHasNoErrors();
        $this->assertNull(AutoReplySetting::withoutAccountScope()->where('account_id', $a->id)->first()->default_flow_id);
    }

    public function test_isolamento_lista_so_fluxos_da_conta_e_rejeita_id_de_outra(): void
    {
        $b = Account::create(['name' => 'B']);
        $fluxoB = Flow::create(['account_id' => $b->id, 'name' => 'Fluxo-Da-B', 'enabled' => true, 'scope' => 'global', 'timeout_seconds' => 300]);
        AutoReplySetting::create(['account_id' => $b->id, 'default_flow_id' => $fluxoB->id]);

        $a = $this->conta('A');
        $fluxoA = $this->fluxo($a, 'Fluxo-Da-A');
        AutoReplySetting::create(['account_id' => $a->id]);

        // (a) a lista de opcoes so tem fluxos de A
        Livewire::test(Configuracoes::class)
            ->assertSee('Fluxo-Da-A')
            ->assertDontSee('Fluxo-Da-B');

        // (b) adulterar o request com o id do fluxo de B -> REJEITADO, nada persiste
        Livewire::test(Configuracoes::class)
            ->set('default_flow_id', $fluxoB->id)
            ->call('save')
            ->assertHasErrors('default_flow_id');
        $this->assertNull(AutoReplySetting::withoutAccountScope()->where('account_id', $a->id)->first()->default_flow_id);

        // (c) salvar em A nao afeta B
        Livewire::test(Configuracoes::class)
            ->set('default_flow_id', $fluxoA->id)
            ->call('save')->assertHasNoErrors();
        $this->assertSame($fluxoB->id, AutoReplySetting::withoutAccountScope()->where('account_id', $b->id)->first()->default_flow_id);
    }

    public function test_fluxo_desabilitado_nao_lista_e_e_rejeitado_como_escolha_nova(): void
    {
        $a = $this->conta('A');
        $off = $this->fluxo($a, 'Fluxo-Desligado', enabled: false);
        AutoReplySetting::create(['account_id' => $a->id]);

        // nao aparece como opcao
        Livewire::test(Configuracoes::class)->assertDontSee('Fluxo-Desligado');

        // escolher como NOVO valor e rejeitado
        Livewire::test(Configuracoes::class)
            ->set('default_flow_id', $off->id)
            ->call('save')
            ->assertHasErrors('default_flow_id');
        $this->assertNull(AutoReplySetting::withoutAccountScope()->where('account_id', $a->id)->first()->default_flow_id);
    }

    public function test_default_salvo_que_foi_desabilitado_aparece_marcado_e_save_mantendo_passa(): void
    {
        $a = $this->conta('A');
        $flow = $this->fluxo($a, 'Era-Habilitado');
        AutoReplySetting::create(['account_id' => $a->id, 'default_flow_id' => $flow->id]);
        $flow->update(['enabled' => false]); // desabilitado DEPOIS de escolhido

        // tela nao quebra: mostra o atual marcado "(desabilitado)"
        $tela = Livewire::test(Configuracoes::class)
            ->assertSet('default_flow_id', $flow->id)
            ->assertSee('Era-Habilitado (desabilitado)');

        // salvar MANTENDO o valor passa (nao e escolha nova); trocar pra null tambem funciona
        $tela->call('save')->assertHasNoErrors();
        $this->assertSame($flow->id, AutoReplySetting::withoutAccountScope()->where('account_id', $a->id)->first()->default_flow_id);
    }

    public function test_deletar_o_fluxo_apontado_zera_para_null(): void
    {
        $a = $this->conta('A');
        $flow = $this->fluxo($a, 'Sera-Apagado');
        $s = AutoReplySetting::create(['account_id' => $a->id, 'default_flow_id' => $flow->id]);

        $flow->delete(); // ON DELETE SET NULL (fatia 1)

        $this->assertNull($s->fresh()->default_flow_id);
    }
}
