<?php

namespace Tests\Feature;

use App\Livewire\Fluxos;
use App\Models\Account;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\FlowOption;
use App\Tenancy\AccountContext;
use App\Whatsapp\Flows\InstantiateFlowTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fatia 17 — visualizacao em ARVORE (read-only) do fluxo, com a politica
 * EXPAND-ONCE: set global de visitados na DFS; reencontro (laco/DAG) vira
 * referencia ↩ sem expandir — terminacao garantida (cada no expande <= 1 vez).
 * Orfaos (inalcancaveis da raiz) em secao separada. Posse herdada do editor.
 */
class FlowTreeViewTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'A']);
        app(AccountContext::class)->set($this->account->id);
    }

    // Fatia 18 (ajuste deliberado): a alternancia virou viewMode de 3 estados
    // (editar|arvore|fluxograma) — o bool treeView da 17 deixou de existir.
    private function abrirArvore(int $flowId)
    {
        return Livewire::test(Fluxos::class)->call('editar', $flowId)->call('setView', 'arvore');
    }

    public function test_arvore_renderiza_estrutura_do_template_com_rotulos_badges_e_trechos(): void
    {
        $flow = app(InstantiateFlowTemplate::class)->handle('clinica', $this->account->id);

        // Fatia 18 (ajuste deliberado): o titulo perdeu o "(somente leitura)" —
        // a arvore ganhou edicao rapida via modal (a estrutura segue read-only).
        $this->abrirArvore($flow->id)
            ->assertSee('Arvore do fluxo')
            ->assertSee('no #1')                 // raiz numerada
            ->assertSee('Agendar consulta')      // rotulo de opcao
            ->assertSee('handoff')               // badge do kind terminal
            ->assertSee('Seja bem-vindo(a)');    // trecho da message da raiz
    }

    public function test_laco_para_ancestral_nao_trava_e_vira_referencia(): void
    {
        // raiz(menu) -> opcao 1 -> filho(menu) -> opcao "voltar" -> RAIZ (ciclo!).
        $f = Flow::create(['account_id' => $this->account->id, 'name' => 'Ciclo', 'enabled' => false, 'timeout_seconds' => 600]);
        $raiz = FlowNode::create(['flow_id' => $f->id, 'kind' => 'menu', 'message' => 'RAIZ-UNICA-XYZ']);
        $filho = FlowNode::create(['flow_id' => $f->id, 'kind' => 'menu', 'message' => 'FILHO-MENU']);
        $f->update(['root_node_id' => $raiz->id]);
        FlowOption::create(['flow_node_id' => $raiz->id, 'input' => '1', 'label' => 'Ir ao filho', 'next_node_id' => $filho->id, 'ordem' => 1]);
        FlowOption::create(['flow_node_id' => $filho->id, 'input' => '0', 'label' => 'Voltar', 'next_node_id' => $raiz->id, 'ordem' => 1]);

        // O TESTE CRITICO: render completa (sem loop infinito), com a referencia.
        $html = $this->abrirArvore($f->id)
            ->assertSee('volta ao')
            ->assertSee("no #{$raiz->display_number}")
            ->html();

        // A raiz EXPANDE uma vez so (a mensagem aparece 1x; o reencontro e so ref).
        $this->assertSame(1, substr_count($html, 'RAIZ-UNICA-XYZ'));
    }

    public function test_dag_no_compartilhado_expande_uma_vez_e_reencontro_e_referencia(): void
    {
        // Dois menus apontam pro MESMO no final.
        $f = Flow::create(['account_id' => $this->account->id, 'name' => 'Dag', 'enabled' => false, 'timeout_seconds' => 600]);
        $raiz = FlowNode::create(['flow_id' => $f->id, 'kind' => 'menu', 'message' => 'MENU RAIZ']);
        $sub = FlowNode::create(['flow_id' => $f->id, 'kind' => 'menu', 'message' => 'SUB MENU']);
        $fim = FlowNode::create(['flow_id' => $f->id, 'kind' => 'final', 'message' => 'FINAL-COMPARTILHADO-ABC']);
        $f->update(['root_node_id' => $raiz->id]);
        FlowOption::create(['flow_node_id' => $raiz->id, 'input' => '1', 'label' => 'Direto ao fim', 'next_node_id' => $fim->id, 'ordem' => 1]);
        FlowOption::create(['flow_node_id' => $raiz->id, 'input' => '2', 'label' => 'Sub-menu', 'next_node_id' => $sub->id, 'ordem' => 2]);
        FlowOption::create(['flow_node_id' => $sub->id, 'input' => '1', 'label' => 'Tambem ao fim', 'next_node_id' => $fim->id, 'ordem' => 1]);

        $html = $this->abrirArvore($f->id)->assertSee('volta ao')->html();

        $this->assertSame(1, substr_count($html, 'FINAL-COMPARTILHADO-ABC')); // subarvore nao duplica
    }

    public function test_orfaos_aparecem_na_secao_nao_conectados(): void
    {
        $f = Flow::create(['account_id' => $this->account->id, 'name' => 'Orfao', 'enabled' => false, 'timeout_seconds' => 600]);
        $raiz = FlowNode::create(['flow_id' => $f->id, 'kind' => 'menu', 'message' => 'RAIZ']);
        $f->update(['root_node_id' => $raiz->id]);
        $orfao = FlowNode::create(['flow_id' => $f->id, 'kind' => 'final', 'message' => 'ORFAO-SOLTO-QWE']);

        $this->abrirArvore($f->id)
            ->assertSee('Nos nao conectados')
            ->assertSee('ORFAO-SOLTO-QWE')
            ->assertSee("no #{$orfao->display_number}");
    }

    public function test_opcao_sem_destino_marcada_inline(): void
    {
        $f = Flow::create(['account_id' => $this->account->id, 'name' => 'SemDestino', 'enabled' => false, 'timeout_seconds' => 600]);
        $raiz = FlowNode::create(['flow_id' => $f->id, 'kind' => 'menu', 'message' => 'RAIZ']);
        $f->update(['root_node_id' => $raiz->id]);
        FlowOption::create(['flow_node_id' => $raiz->id, 'input' => '1', 'label' => 'Perdida', 'next_node_id' => null, 'ordem' => 1]);

        $this->abrirArvore($f->id)->assertSee('sem destino');
    }

    public function test_arvore_e_read_only_sem_acoes_de_escrita(): void
    {
        $flow = app(InstantiateFlowTemplate::class)->handle('comercio', $this->account->id);

        // No modo arvore os cards de edicao (Salvar no/remover/opcao) nao renderizam.
        $this->abrirArvore($flow->id)
            ->assertDontSee('Salvar no')
            ->assertDontSee('salvarNo(');
    }

    public function test_posse_arvore_de_fluxo_de_outra_conta_e_rejeitada(): void
    {
        $b = Account::create(['name' => 'B']);
        $fluxoB = Flow::create(['account_id' => $b->id, 'name' => 'Da-B', 'enabled' => false, 'timeout_seconds' => 600]);

        // A arvore herda o guard do editor (findOrFail escopado por conta).
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test(Fluxos::class)->call('editar', $fluxoB->id);
    }

    public function test_hint_do_handoff_corrigido_aguardando_resposta(): void
    {
        $flow = app(InstantiateFlowTemplate::class)->handle('clinica', $this->account->id);

        // Modo EDITAR (onde o hint mora): copy nova, e a antiga sumiu.
        Livewire::test(Fluxos::class)->call('editar', $flow->id)
            ->assertSee('Aguardando resposta')
            ->assertDontSee('move o card pra Em atendimento');
    }
}
