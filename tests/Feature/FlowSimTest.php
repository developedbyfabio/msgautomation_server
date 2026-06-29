<?php

namespace Tests\Feature;

use App\Livewire\Fluxos;
use App\Models\Account;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\FlowSession;
use App\Whatsapp\Secrets\SecretVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * C.1 — testador de fluxo (dry-run): navega sem enviar, sem persistir sessao,
 * senha mascarada por padrao.
 */
class FlowSimTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;
    private Flow $flow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'T']);
        $this->flow = $this->build();
    }

    private function build(): Flow
    {
        $flow = Flow::create(['account_id' => $this->account->id, 'name' => 'F', 'enabled' => true, 'scope' => 'contatos', 'timeout_seconds' => 600]);
        $flow->triggers()->create(['match_type' => 'contains', 'match_value' => 'menu']);
        $root = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'menu', 'message' => 'Menu: 1 - Wifi']);
        $wifi = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'final', 'message' => 'A senha e {senha:wifi}']);
        $root->options()->create(['input' => '1', 'label' => '1 - Wifi', 'next_node_id' => $wifi->id]);
        $flow->update(['root_node_id' => $root->id]);

        return $flow->fresh();
    }

    public function test_simula_navegacao_sem_criar_sessao(): void
    {
        app(SecretVault::class)->put($this->account->id, 'wifi', 'TopSecret#1');

        $c = Livewire::test(Fluxos::class)
            ->call('editar', $this->flow->id)
            ->call('iniciarSim')
            ->assertSee('Menu: 1 - Wifi');

        // Avanca pra opcao 1 (nó final com senha) -> MASCARADA por padrao.
        $c->set('simInput', '1')->call('enviarSim');
        $this->assertSame('completed', $c->get('simStatus'));

        // Nenhuma sessao real criada (dry-run).
        $this->assertSame(0, FlowSession::count());
    }

    public function test_senha_mascarada_por_padrao_e_revelavel(): void
    {
        app(SecretVault::class)->put($this->account->id, 'wifi', 'TopSecret#1');

        $c = Livewire::test(Fluxos::class)
            ->call('editar', $this->flow->id)
            ->call('iniciarSim')
            ->set('simInput', '1')->call('enviarSim')
            ->assertDontSee('TopSecret#1')   // mascarada
            ->assertSee('••••');

        $c->call('toggleSimReveal')->assertSee('TopSecret#1'); // revelar deliberado
    }

    public function test_opcao_invalida_repergunta(): void
    {
        Livewire::test(Fluxos::class)
            ->call('editar', $this->flow->id)
            ->call('iniciarSim')
            ->set('simInput', '9')->call('enviarSim')
            ->assertSet('simStatus', 'active')
            ->assertSee('invalida');
    }
}
