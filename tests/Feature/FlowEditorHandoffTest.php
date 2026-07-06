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
 * Fatia 5b — handoff no EDITOR de fluxos (authoring): criar/editar no handoff,
 * opcao de menu apontando pra handoff, render de handoff pre-existente (como os
 * templates da Fatia 7 vao instanciar), validacao (message obrigatoria, terminal)
 * e isolamento por conta. O motor (Fatia 5) nao e tocado aqui.
 */
class FlowEditorHandoffTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'T']);
        app(AccountContext::class)->set($this->account->id);
    }

    /** Fluxo com root menu, como o editor cria. */
    private function fluxoComRoot(): array
    {
        $flow = Flow::create(['account_id' => $this->account->id, 'name' => 'F', 'enabled' => false, 'scope' => 'global', 'timeout_seconds' => 600]);
        $root = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'menu', 'message' => 'Menu', 'ordem' => 0]);
        $flow->update(['root_node_id' => $root->id]);

        return [$flow, $root];
    }

    // ---- Criar handoff via editor --------------------------------------------

    public function test_destino_novo_handoff_cria_no_handoff_e_liga_a_opcao(): void
    {
        [$flow, $root] = $this->fluxoComRoot();

        $c = Livewire::test(Fluxos::class)->call('editar', $flow->id);
        $c->call('addOpcao', $root->id);
        $opt = FlowOption::where('flow_node_id', $root->id)->first();

        $c->call('definirDestino', $opt->id, 'novo_handoff');

        $handoff = FlowNode::where('flow_id', $flow->id)->where('kind', 'handoff')->first();
        $this->assertNotNull($handoff);
        $this->assertSame('Um atendente vai te responder em breve.', $handoff->message);
        $this->assertSame($root->id, (int) $handoff->parent_node_id);
        $this->assertSame($handoff->id, (int) $opt->fresh()->next_node_id); // opcao aponta pro handoff
        $this->assertSame(0, $handoff->options()->count()); // terminal: nasce sem opcoes
    }

    public function test_trocar_no_existente_pra_handoff_com_mensagem_persiste(): void
    {
        [$flow, $root] = $this->fluxoComRoot();
        $no = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'final', 'message' => 'Fim', 'ordem' => 1]);

        Livewire::test(Fluxos::class)->call('editar', $flow->id)
            ->set("nodeKind.{$no->id}", 'handoff')
            ->set("nodeMsg.{$no->id}", 'Vou te passar pra um atendente.')
            ->call('salvarNo', $no->id);

        $no->refresh();
        $this->assertSame('handoff', $no->kind);
        $this->assertSame('Vou te passar pra um atendente.', $no->message);

        // Recarregar o editor reflete o que foi salvo (round-trip).
        Livewire::test(Fluxos::class)->call('editar', $flow->id)
            ->assertSet("nodeKind.{$no->id}", 'handoff')
            ->assertSet("nodeMsg.{$no->id}", 'Vou te passar pra um atendente.');
    }

    // ---- Opcao de menu -> handoff EXISTENTE (por id) --------------------------

    public function test_opcao_pode_apontar_pra_handoff_existente(): void
    {
        [$flow, $root] = $this->fluxoComRoot();
        $handoff = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'handoff', 'message' => 'Atendente a caminho.', 'ordem' => 1]);
        $opt = FlowOption::create(['flow_node_id' => $root->id, 'input' => '1', 'label' => 'Atendente', 'next_node_id' => null, 'ordem' => 1]);

        Livewire::test(Fluxos::class)->call('editar', $flow->id)
            ->call('definirDestino', $opt->id, (string) $handoff->id);

        $this->assertSame($handoff->id, (int) $opt->fresh()->next_node_id);

        // e o handoff aparece como destino no select (render da arvore o inclui).
        // Fatia 17 (ajuste deliberado): o ROTULO agora exibe o numero POR FLUXO
        // (display_number), nao a PK — o value do select segue sendo o id real
        // (provado acima: definirDestino persistiu $handoff->id).
        Livewire::test(Fluxos::class)->call('editar', $flow->id)
            ->assertSee("no #{$handoff->fresh()->display_number} (handoff)");
    }

    // ---- Handoff PRE-EXISTENTE (como um template da Fatia 7 instanciaria) ------

    public function test_fluxo_com_handoff_pre_existente_abre_e_edita_sem_quebrar(): void
    {
        // Montado programaticamente (fora do editor), como um template faria.
        $flow = Flow::create(['account_id' => $this->account->id, 'name' => 'Template', 'enabled' => true, 'scope' => 'global', 'timeout_seconds' => 600]);
        $root = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'menu', 'message' => 'MENU: 1-Falar com atendente', 'ordem' => 0]);
        $handoff = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'handoff', 'message' => 'Um atendente vai te responder em breve.', 'parent_node_id' => $root->id, 'ordem' => 1]);
        FlowOption::create(['flow_node_id' => $root->id, 'input' => '1', 'label' => 'Atendente', 'next_node_id' => $handoff->id, 'ordem' => 1]);
        $flow->update(['root_node_id' => $root->id]);

        // Abre sem erro, exibindo o no e sua mensagem, com buffers carregados.
        $c = Livewire::test(Fluxos::class)->call('editar', $flow->id)
            ->assertSee('Um atendente vai te responder em breve.')
            ->assertSet("nodeKind.{$handoff->id}", 'handoff')
            ->assertSet("nodeMsg.{$handoff->id}", 'Um atendente vai te responder em breve.');

        // Edita a mensagem e salva.
        $c->set("nodeMsg.{$handoff->id}", 'Aguarde um instante, ja te atendo!')
            ->call('salvarNo', $handoff->id);

        $handoff->refresh();
        $this->assertSame('handoff', $handoff->kind); // kind preservado
        $this->assertSame('Aguarde um instante, ja te atendo!', $handoff->message);
    }

    // ---- Validacao: message obrigatoria + terminal -----------------------------

    public function test_handoff_sem_mensagem_e_rejeitado_sem_persistir(): void
    {
        [$flow, $root] = $this->fluxoComRoot();
        $no = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'final', 'message' => 'Fim', 'ordem' => 1]);

        Livewire::test(Fluxos::class)->call('editar', $flow->id)
            ->set("nodeKind.{$no->id}", 'handoff')
            ->set("nodeMsg.{$no->id}", '   ')
            ->call('salvarNo', $no->id);

        $no->refresh();
        $this->assertSame('final', $no->kind);   // nada persistiu
        $this->assertSame('Fim', $no->message);
    }

    public function test_no_com_opcoes_nao_pode_virar_handoff(): void
    {
        [$flow, $root] = $this->fluxoComRoot();
        FlowOption::create(['flow_node_id' => $root->id, 'input' => '1', 'label' => 'X', 'next_node_id' => null, 'ordem' => 1]);

        $c = Livewire::test(Fluxos::class)->call('editar', $flow->id)
            ->set("nodeKind.{$root->id}", 'handoff')
            ->set("nodeMsg.{$root->id}", 'Chamar atendente')
            ->call('salvarNo', $root->id);

        $root->refresh();
        $this->assertSame('menu', $root->kind); // rejeitado: handoff e terminal
        $c->assertSet("nodeKind.{$root->id}", 'menu'); // buffer revertido (select volta)
        $this->assertSame(1, $root->options()->count()); // opcao intacta
    }

    public function test_add_opcao_em_handoff_e_recusado(): void
    {
        [$flow, $root] = $this->fluxoComRoot();
        $handoff = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'handoff', 'message' => 'Atendente.', 'ordem' => 1]);

        Livewire::test(Fluxos::class)->call('editar', $flow->id)
            ->call('addOpcao', $handoff->id);

        $this->assertSame(0, $handoff->options()->count()); // terminal: nenhuma opcao criada
    }

    // ---- Isolamento por conta ---------------------------------------------------

    public function test_isolamento_nao_edita_nem_liga_nos_de_outra_conta(): void
    {
        // Conta B com fluxo + handoff proprios.
        $b = Account::create(['name' => 'B']);
        $flowB = Flow::create(['account_id' => $b->id, 'name' => 'FB', 'enabled' => false, 'scope' => 'global', 'timeout_seconds' => 600]);
        $handoffB = FlowNode::create(['flow_id' => $flowB->id, 'kind' => 'handoff', 'message' => 'Handoff da B', 'ordem' => 0]);
        $optB = FlowOption::create(['flow_node_id' => $handoffB->id, 'input' => '1', 'label' => 'B', 'next_node_id' => null, 'ordem' => 1]);

        // Conta A (contexto ativo) com fluxo proprio.
        [$flowA, $rootA] = $this->fluxoComRoot();
        $optA = FlowOption::create(['flow_node_id' => $rootA->id, 'input' => '1', 'label' => 'A', 'next_node_id' => null, 'ordem' => 1]);

        $c = Livewire::test(Fluxos::class)->call('editar', $flowA->id);

        // (a) salvarNo com id de no da B -> no-op (ownNode nega).
        $c->set("nodeKind.{$handoffB->id}", 'menu')
            ->set("nodeMsg.{$handoffB->id}", 'invadido')
            ->call('salvarNo', $handoffB->id);
        $this->assertSame('handoff', $handoffB->fresh()->kind);
        $this->assertSame('Handoff da B', $handoffB->fresh()->message);

        // (b) definirDestino em opcao da B -> no-op (ownOption nega).
        $c->call('definirDestino', $optB->id, 'novo_handoff');
        $this->assertNull($optB->fresh()->next_node_id);
        $this->assertSame(0, FlowNode::where('flow_id', $flowB->id)->where('id', '!=', $handoffB->id)->count()); // nada criado na B

        // (c) opcao de A apontando pro handoff da B (outro fluxo) -> destino limpo, nao liga.
        $c->call('definirDestino', $optA->id, (string) $handoffB->id);
        $this->assertNull($optA->fresh()->next_node_id);

        // (d) addOpcao no handoff da B -> no-op.
        $c->call('addOpcao', $handoffB->id);
        $this->assertSame(1, $handoffB->options()->count()); // so a optB original
    }

    // ---- Regressao leve: menu/final no editor inalterados -----------------------

    public function test_menu_e_final_no_editor_seguem_como_antes(): void
    {
        [$flow, $root] = $this->fluxoComRoot();

        $c = Livewire::test(Fluxos::class)->call('editar', $flow->id);
        $c->call('addOpcao', $root->id);
        $opt = FlowOption::where('flow_node_id', $root->id)->first();

        // novo_final continua criando final; salvarNo de menu segue aceitando mensagem vazia.
        $c->call('definirDestino', $opt->id, 'novo_final');
        $final = FlowNode::where('flow_id', $flow->id)->where('kind', 'final')->first();
        $this->assertNotNull($final);
        $this->assertSame($final->id, (int) $opt->fresh()->next_node_id);

        $c->set("nodeMsg.{$root->id}", '')->call('salvarNo', $root->id);
        $this->assertSame('menu', $root->fresh()->kind);
        $this->assertSame('', (string) $root->fresh()->message);
    }
}
