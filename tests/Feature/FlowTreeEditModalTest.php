<?php

namespace Tests\Feature;

use App\Livewire\Fluxos;
use App\Models\Account;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\FlowOption;
use App\Tenancy\AccountContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fatia 18 — modal de edicao rapida a partir da ARVORE: texto apenas (message
 * + rotulos das opcoes), salvando pelas MESMAS actions da 5b (salvarNo/
 * salvarOpcao) com validacoes e posse intactas. "Edicao completa" alterna pro
 * modo Editar. O fluxograma e read-only. Botoes do simulador viram botoes.
 */
class FlowTreeEditModalTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'A']);
        app(AccountContext::class)->set($this->account->id);
    }

    /** Fluxo: raiz menu com 1 opcao -> final. Retorna [flow, raiz, final, opcao]. */
    private function fluxo(): array
    {
        $f = Flow::create(['account_id' => $this->account->id, 'name' => 'F', 'enabled' => false, 'timeout_seconds' => 600]);
        $raiz = FlowNode::create(['flow_id' => $f->id, 'kind' => 'menu', 'message' => 'MENU ORIGINAL']);
        $fim = FlowNode::create(['flow_id' => $f->id, 'kind' => 'final', 'message' => 'FIM ORIGINAL']);
        $f->update(['root_node_id' => $raiz->id]);
        $opt = FlowOption::create(['flow_node_id' => $raiz->id, 'input' => '1', 'label' => 'Rotulo Original', 'next_node_id' => $fim->id, 'ordem' => 1]);

        return [$f, $raiz, $fim, $opt];
    }

    public function test_abrir_carrega_valores_atuais_e_salvar_persiste_pelas_actions(): void
    {
        [$f, $raiz, , $opt] = $this->fluxo();

        $tela = Livewire::test(Fluxos::class)->call('editar', $f->id)->call('setView', 'arvore')
            ->call('abrirEdicaoNo', $raiz->id)
            ->assertSet('treeEditNodeId', $raiz->id)
            // Valores ATUAIS carregados (buffers da 5b).
            ->assertSet("nodeMsg.{$raiz->id}", 'MENU ORIGINAL')
            ->assertSet("optBuf.{$opt->id}.label", 'Rotulo Original');

        // Edita message + rotulo e salva: persiste via salvarNo/salvarOpcao.
        $tela->set("nodeMsg.{$raiz->id}", 'MENU NOVO')
            ->set("optBuf.{$opt->id}.label", 'Rotulo Novo')
            ->call('salvarEdicaoNo')
            ->assertSet('treeEditNodeId', null); // fechou

        $this->assertSame('MENU NOVO', $raiz->fresh()->message);
        $this->assertSame('Rotulo Novo', $opt->fresh()->label);

        // A arvore re-renderizada reflete o texto novo.
        $tela->assertSee('MENU NOVO')->assertSee('Rotulo Novo');
    }

    public function test_validacao_existente_vale_handoff_sem_message_rejeita_e_modal_fica_aberto(): void
    {
        $f = Flow::create(['account_id' => $this->account->id, 'name' => 'H', 'enabled' => false, 'timeout_seconds' => 600]);
        $raiz = FlowNode::create(['flow_id' => $f->id, 'kind' => 'menu', 'message' => 'MENU']);
        $handoff = FlowNode::create(['flow_id' => $f->id, 'kind' => 'handoff', 'message' => 'Atendente vem ai.']);
        $f->update(['root_node_id' => $raiz->id]);
        FlowOption::create(['flow_node_id' => $raiz->id, 'input' => '1', 'label' => 'Humano', 'next_node_id' => $handoff->id, 'ordem' => 1]);

        Livewire::test(Fluxos::class)->call('editar', $f->id)->call('setView', 'arvore')
            ->call('abrirEdicaoNo', $handoff->id)
            ->set("nodeMsg.{$handoff->id}", '   ')   // handoff SEM message
            ->call('salvarEdicaoNo')
            ->assertSet('treeEditNodeId', $handoff->id); // modal FICA aberto (rejeitado)

        $this->assertSame('Atendente vem ai.', $handoff->fresh()->message); // nada persistiu
    }

    public function test_posse_no_de_outra_conta_nao_abre_nem_salva(): void
    {
        [$f] = $this->fluxo();
        $b = Account::create(['name' => 'B']);
        $fluxoB = Flow::create(['account_id' => $b->id, 'name' => 'Da-B', 'enabled' => false, 'timeout_seconds' => 600]);
        $noB = FlowNode::create(['flow_id' => $fluxoB->id, 'kind' => 'menu', 'message' => 'SEGREDO-DA-B']);

        // Abrir apontando no de OUTRA conta: no-op (ownNode nega).
        $tela = Livewire::test(Fluxos::class)->call('editar', $f->id)->call('setView', 'arvore')
            ->call('abrirEdicaoNo', $noB->id)
            ->assertSet('treeEditNodeId', null);

        // Salvar forjado com id alheio setado na marra: tambem no-op.
        $tela->set('treeEditNodeId', $noB->id)
            ->set("nodeMsg.{$noB->id}", 'INVADIDO')
            ->call('salvarEdicaoNo');
        $this->assertSame('SEGREDO-DA-B', $noB->fresh()->message); // intacto
    }

    public function test_edicao_completa_alterna_pro_modo_editar(): void
    {
        [$f, $raiz] = $this->fluxo();

        Livewire::test(Fluxos::class)->call('editar', $f->id)->call('setView', 'arvore')
            ->call('abrirEdicaoNo', $raiz->id)
            ->call('edicaoCompleta')
            ->assertSet('viewMode', 'editar')
            ->assertSet('treeEditNodeId', null)
            ->assertSee('Salvar no'); // cards do modo Editar renderizados
    }

    public function test_fluxograma_dispara_evento_com_dsl_e_e_read_only(): void
    {
        $f = app(\App\Whatsapp\Flows\InstantiateFlowTemplate::class)->handle('comercio', $this->account->id);

        Livewire::test(Fluxos::class)->call('editar', $f->id)
            ->call('setView', 'fluxograma')
            ->assertSet('viewMode', 'fluxograma')
            ->assertDispatched('fluxograma-render')      // DSL entregue pro JS
            ->assertSee('Fluxograma (somente leitura)')
            ->assertSee('Gerando fluxograma...')
            ->assertDontSee('Salvar no');                // nenhuma acao de escrita
    }

    public function test_botoes_do_simulador_sao_botoes_com_aria_label(): void
    {
        [$f] = $this->fluxo();

        Livewire::test(Fluxos::class)->call('editar', $f->id)
            ->call('iniciarSim')
            ->assertSeeHtml('aria-label="Revelar senha"')
            ->assertSeeHtml('aria-label="Reiniciar simulacao"')
            ->assertSeeHtml('aria-label="Fechar simulacao"');
    }
}
