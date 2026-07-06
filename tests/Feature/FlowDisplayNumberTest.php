<?php

namespace Tests\Feature;

use App\Livewire\Fluxos;
use App\Models\Account;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\FlowOption;
use App\Tenancy\AccountContext;
use App\Whatsapp\Flows\DuplicateFlow;
use App\Whatsapp\Flows\InstantiateFlowTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fatia 17 — numeracao de nos POR FLUXO (display_number): mata o "fluxo 5 com
 * no #20" (a PK e auto-increment global da tabela e vazava pra UI). A PK segue
 * sendo a chave de dados (FKs e value dos selects); o display_number e SO
 * exibicao. Atribuicao no hook creating (choke point unico); ESTAVEL: deletar
 * nao renumera; duplicacao ganha numeracao fresca contigua.
 */
class FlowDisplayNumberTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'A']);
        app(AccountContext::class)->set($this->account->id);
    }

    private function fluxo(string $nome = 'F'): Flow
    {
        return Flow::create(['account_id' => $this->account->id, 'name' => $nome, 'enabled' => false, 'timeout_seconds' => 600]);
    }

    public function test_fluxo_novo_comeca_em_1_mesmo_com_outros_fluxos_na_tabela(): void
    {
        // Fluxo A consome PKs 1..3 da tabela global.
        $a = $this->fluxo('A');
        foreach (range(1, 3) as $i) {
            FlowNode::create(['flow_id' => $a->id, 'kind' => 'menu', 'message' => "A{$i}"]);
        }

        // O TESTE QUE MATA O BUG: fluxo B novo comeca em 1 (a PK do primeiro no
        // dele e >= 4, mas o numero exibido e 1, 2, 3...).
        $b = $this->fluxo('B');
        $n1 = FlowNode::create(['flow_id' => $b->id, 'kind' => 'menu', 'message' => 'B1']);
        $n2 = FlowNode::create(['flow_id' => $b->id, 'kind' => 'final', 'message' => 'B2']);

        $this->assertGreaterThan(3, $n1->id); // PK global segue acumulando (interna)
        $this->assertSame(1, (int) $n1->display_number);
        $this->assertSame(2, (int) $n2->display_number);
    }

    public function test_deletar_nao_renumera_e_o_proximo_e_max_mais_1(): void
    {
        $f = $this->fluxo();
        $n1 = FlowNode::create(['flow_id' => $f->id, 'kind' => 'menu', 'message' => '1']);
        $n2 = FlowNode::create(['flow_id' => $f->id, 'kind' => 'final', 'message' => '2']);
        $n3 = FlowNode::create(['flow_id' => $f->id, 'kind' => 'final', 'message' => '3']);

        $n2->delete(); // buraco no meio

        // Os demais MANTÊM os numeros (referencias visuais estaveis)...
        $this->assertSame(1, (int) $n1->fresh()->display_number);
        $this->assertSame(3, (int) $n3->fresh()->display_number);
        // ...e o proximo continua do max+1 (buraco preservado, como issue tracker).
        $n4 = FlowNode::create(['flow_id' => $f->id, 'kind' => 'final', 'message' => '4']);
        $this->assertSame(4, (int) $n4->display_number);
    }

    public function test_unique_por_fluxo_e_isolamento_entre_fluxos_e_contas(): void
    {
        $f = $this->fluxo();
        FlowNode::create(['flow_id' => $f->id, 'kind' => 'menu', 'message' => 'x']); // #1

        // MESMO numero no MESMO fluxo: barrado pelo unique composto.
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        FlowNode::create(['flow_id' => $f->id, 'kind' => 'final', 'message' => 'y', 'display_number' => 1]);
    }

    public function test_numeracao_independente_entre_contas(): void
    {
        $f = $this->fluxo();
        FlowNode::create(['flow_id' => $f->id, 'kind' => 'menu', 'message' => 'a']);

        $b = Account::create(['name' => 'B']);
        $fb = Flow::create(['account_id' => $b->id, 'name' => 'FB', 'enabled' => false, 'timeout_seconds' => 600]);
        $nb = FlowNode::create(['flow_id' => $fb->id, 'kind' => 'menu', 'message' => 'b']);

        $this->assertSame(1, (int) $nb->display_number); // escopo e por flow_id
    }

    public function test_template_instancia_1_a_n_e_duplicacao_ganha_numeracao_fresca(): void
    {
        $original = app(InstantiateFlowTemplate::class)->handle('clinica', $this->account->id);
        $numeros = FlowNode::where('flow_id', $original->id)->orderBy('id')->pluck('display_number')->map(fn ($n) => (int) $n)->all();
        $this->assertSame(range(1, count($numeros)), $numeros); // 1..N contiguo

        // Buraco no original (deleta um no folha) — numeracao do original fica.
        $folha = FlowNode::where('flow_id', $original->id)->where('kind', 'final')->orderBy('id')->first();
        FlowOption::where('next_node_id', $folha->id)->update(['next_node_id' => null]);
        $folha->delete();
        $numerosOriginal = FlowNode::where('flow_id', $original->id)->orderBy('id')->pluck('display_number')->map(fn ($n) => (int) $n)->all();

        // A COPIA ganha numeracao FRESCA e contigua (fluxo novo), original intacto.
        $copia = app(DuplicateFlow::class)->handle($original->id, $this->account->id);
        $numerosCopia = FlowNode::where('flow_id', $copia->id)->orderBy('id')->pluck('display_number')->map(fn ($n) => (int) $n)->all();
        $this->assertSame(range(1, count($numerosCopia)), $numerosCopia);
        $this->assertSame($numerosOriginal, FlowNode::where('flow_id', $original->id)->orderBy('id')->pluck('display_number')->map(fn ($n) => (int) $n)->all());
    }

    public function test_ui_exibe_display_number_e_o_value_do_select_preserva_a_pk(): void
    {
        // Avanca as PKs com um fluxo anterior (pk != display na proxima criacao).
        $a = $this->fluxo('A');
        foreach (range(1, 5) as $i) {
            FlowNode::create(['flow_id' => $a->id, 'kind' => 'final', 'message' => "pad{$i}"]);
        }

        $f = $this->fluxo('B');
        $root = FlowNode::create(['flow_id' => $f->id, 'kind' => 'menu', 'message' => 'MENU']);
        $destino = FlowNode::create(['flow_id' => $f->id, 'kind' => 'final', 'message' => 'FIM']);
        $f->update(['root_node_id' => $root->id]);
        $opt = FlowOption::create(['flow_node_id' => $root->id, 'input' => '1', 'label' => 'Fim', 'next_node_id' => null, 'ordem' => 1]);

        $tela = Livewire::test(Fluxos::class)->call('editar', $f->id)
            ->assertSee('no #1')                                  // rotulo novo (por fluxo)
            ->assertDontSee("no #{$root->id}")                    // a PK sumiu da UI
            ->assertSee("no #{$destino->display_number} (final)") // rotulo do select
            ->assertSeeHtml('value="' . $destino->id . '"');      // VALUE segue sendo a PK

        // Salvar destino persiste o id REAL (a semantica de dados nao mudou).
        $tela->call('definirDestino', $opt->id, (string) $destino->id);
        $this->assertSame($destino->id, (int) $opt->fresh()->next_node_id);
    }

    public function test_warnings_falam_o_numero_por_fluxo(): void
    {
        // Padding pra PK != display_number.
        $a = $this->fluxo('A');
        FlowNode::create(['flow_id' => $a->id, 'kind' => 'final', 'message' => 'pad']);

        $f = $this->fluxo('B');
        $root = FlowNode::create(['flow_id' => $f->id, 'kind' => 'menu', 'message' => 'MENU sem opcao']);
        $f->update(['root_node_id' => $root->id]);

        Livewire::test(Fluxos::class)->call('editar', $f->id)
            ->assertSee("No #{$root->display_number} e menu mas nao tem opcao")
            ->assertDontSee("No #{$root->id} e menu");
    }

    public function test_cor_de_identidade_deterministica_e_ciclo_de_12(): void
    {
        $f = $this->fluxo();
        $n1 = FlowNode::create(['flow_id' => $f->id, 'kind' => 'menu', 'message' => 'x']); // #1

        // Deterministica: mesma classe entre chamadas.
        $this->assertSame($n1->identityColor(), $n1->fresh()->identityColor());
        $this->assertSame(FlowNode::IDENTITY_COLORS[0], $n1->identityColor());

        // Ciclo de 12: no #13 compartilha a cor do #1.
        $n13 = new FlowNode(['display_number' => 13]);
        $this->assertSame($n1->identityColor(), $n13->identityColor());
        $this->assertCount(12, FlowNode::IDENTITY_COLORS);
    }
}
