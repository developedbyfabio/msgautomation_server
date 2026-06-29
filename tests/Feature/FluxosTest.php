<?php

namespace Tests\Feature;

use App\Livewire\Fluxos;
use App\Models\Account;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\FlowOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fatia B — construtor de fluxos (UI). Persistencia por acao no banco.
 */
class FluxosTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'T']);
    }

    public function test_novo_fluxo_cria_rascunho_e_no_raiz(): void
    {
        Livewire::test(Fluxos::class)->call('novoFluxo')->assertSet('enabled', false);

        $flow = Flow::first();
        $this->assertNotNull($flow);
        $this->assertFalse((bool) $flow->enabled);
        $this->assertNotNull($flow->root_node_id);
        $this->assertSame('menu', FlowNode::find($flow->root_node_id)->kind);
    }

    public function test_salvar_config_persiste(): void
    {
        Livewire::test(Fluxos::class)
            ->call('novoFluxo')
            ->set('name', 'Atendimento')
            ->set('timeout_seconds', 300)
            ->set('triggers.0.type', 'contains')
            ->set('triggers.0.value', 'menu')
            ->call('salvarConfig')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('flows', ['name' => 'Atendimento', 'timeout_seconds' => 300]);
        $this->assertDatabaseHas('flow_triggers', ['match_type' => 'contains', 'match_value' => 'menu']);
    }

    public function test_toggle_exige_gatilho_e_raiz(): void
    {
        // Fluxo so com raiz, sem gatilho -> nao liga.
        $flow = Flow::create(['account_id' => $this->account->id, 'name' => 'F', 'enabled' => false]);
        $root = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'menu', 'message' => 'x']);
        $flow->update(['root_node_id' => $root->id]);

        Livewire::test(Fluxos::class)->call('toggleFluxo', $flow->id);
        $this->assertFalse((bool) $flow->fresh()->enabled);

        // Com gatilho -> liga.
        $flow->triggers()->create(['match_type' => 'contains', 'match_value' => 'menu']);
        Livewire::test(Fluxos::class)->call('toggleFluxo', $flow->id);
        $this->assertTrue((bool) $flow->fresh()->enabled);
    }

    public function test_add_opcao_e_destino_novo_final(): void
    {
        $c = Livewire::test(Fluxos::class)->call('novoFluxo');
        $flow = Flow::first();
        $rootId = (int) $flow->root_node_id;

        $c->call('addOpcao', $rootId);
        $opt = FlowOption::where('flow_node_id', $rootId)->first();
        $this->assertNotNull($opt);
        $this->assertNull($opt->next_node_id);

        $c->call('definirDestino', $opt->id, 'novo_final');
        $opt->refresh();
        $this->assertNotNull($opt->next_node_id);
        $this->assertSame('final', FlowNode::find($opt->next_node_id)->kind);
        // O novo nó e filho do root.
        $this->assertSame($rootId, (int) FlowNode::find($opt->next_node_id)->parent_node_id);
    }

    public function test_remover_no_bloqueia_raiz(): void
    {
        $c = Livewire::test(Fluxos::class)->call('novoFluxo');
        $flow = Flow::first();
        $rootId = (int) $flow->root_node_id;

        $c->call('removerNo', $rootId);
        $this->assertDatabaseHas('flow_nodes', ['id' => $rootId]); // raiz nao foi apagada
    }

    public function test_guarda_de_senha_bloqueia_ligar_fluxo_global(): void
    {
        $flow = Flow::create(['account_id' => $this->account->id, 'name' => 'F', 'enabled' => false, 'scope' => 'global']);
        $root = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'final', 'message' => 'A senha e {senha:wifi}']);
        $flow->update(['root_node_id' => $root->id]);
        $flow->triggers()->create(['match_type' => 'contains', 'match_value' => 'senha']);

        // Global + {senha:} -> nao liga.
        Livewire::test(Fluxos::class)->call('toggleFluxo', $flow->id);
        $this->assertFalse((bool) $flow->fresh()->enabled);

        // Contatos -> liga.
        $flow->update(['scope' => 'contatos']);
        Livewire::test(Fluxos::class)->call('toggleFluxo', $flow->id);
        $this->assertTrue((bool) $flow->fresh()->enabled);
    }

    public function test_guarda_senha_exige_gatilho_estrito(): void
    {
        $flow = Flow::create(['account_id' => $this->account->id, 'name' => 'F', 'enabled' => false, 'scope' => 'contatos']);
        $root = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'final', 'message' => '{senha:wifi}']);
        $flow->update(['root_node_id' => $root->id]);
        $flow->triggers()->create(['match_type' => 'contains', 'match_value' => 'senha', 'precision' => 'tolerante']);
        $c = \App\Models\Contact::create(['account_id' => $this->account->id, 'remote_jid' => 'a@s.whatsapp.net', 'auto_reply_mode' => 'on']);
        $flow->contacts()->attach($c->id);

        // Contatos OK, mas gatilho tolerante -> nao liga.
        Livewire::test(Fluxos::class)->call('toggleFluxo', $flow->id);
        $this->assertFalse((bool) $flow->fresh()->enabled);

        // Gatilho estrito -> liga.
        $flow->triggers()->update(['precision' => 'exato']);
        Livewire::test(Fluxos::class)->call('toggleFluxo', $flow->id);
        $this->assertTrue((bool) $flow->fresh()->enabled);
    }

    public function test_definir_destino_no_existente(): void
    {
        $c = Livewire::test(Fluxos::class)->call('novoFluxo');
        $flow = Flow::first();
        $rootId = (int) $flow->root_node_id;
        $outro = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'final', 'message' => 'fim']);

        $c->call('addOpcao', $rootId);
        $opt = FlowOption::where('flow_node_id', $rootId)->first();
        $c->call('definirDestino', $opt->id, (string) $outro->id);

        $this->assertSame($outro->id, (int) $opt->fresh()->next_node_id);
    }
}
