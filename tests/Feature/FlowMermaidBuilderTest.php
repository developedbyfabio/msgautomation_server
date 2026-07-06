<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\FlowOption;
use App\Tenancy\AccountContext;
use App\Whatsapp\Flows\FlowMermaidBuilder;
use App\Whatsapp\Flows\InstantiateFlowTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fatia 18 — FlowMermaidBuilder: DSL do fluxograma gerada SERVER-SIDE.
 * Shapes por kind (menu losango, final terminal, handoff subrotina), arestas
 * rotuladas por opcao, cada no declarado UMA vez (ciclo = so uma aresta a
 * mais), orfaos soltos, labels SANITIZADOS (texto de usuario nunca injeta
 * sintaxe), cores hex do mesmo ciclo da fatia 17.
 */
class FlowMermaidBuilderTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'A']);
        app(AccountContext::class)->set($this->account->id);
    }

    private function build(Flow $flow): string
    {
        return app(FlowMermaidBuilder::class)->build($flow);
    }

    public function test_template_completo_gera_shapes_arestas_e_numeros(): void
    {
        $flow = app(InstantiateFlowTemplate::class)->handle('clinica', $this->account->id);
        $dsl = $this->build($flow);

        $this->assertStringStartsWith('flowchart TD', $dsl);
        // Shapes por kind: losango (menu raiz), terminal (final), subrotina (handoff).
        $raiz = FlowNode::where('flow_id', $flow->id)->where('kind', 'menu')->firstOrFail();
        $final = FlowNode::where('flow_id', $flow->id)->where('kind', 'final')->firstOrFail();
        $handoff = FlowNode::where('flow_id', $flow->id)->where('kind', 'handoff')->firstOrFail();
        $this->assertStringContainsString("n{$raiz->id}{\"", $dsl);      // losango de decisao
        $this->assertStringContainsString("n{$final->id}([\"", $dsl);    // terminal
        $this->assertStringContainsString("n{$handoff->id}[[\"", $dsl);  // subrotina destacada
        // Rotulo com o numero POR FLUXO.
        $this->assertStringContainsString('#' . $raiz->display_number . ' ·', $dsl);
        // Aresta rotulada com o texto da opcao.
        $this->assertStringContainsString('-->|"1 - Agendar consulta"|', $dsl);
    }

    public function test_ciclo_declara_o_no_uma_vez_e_gera_a_aresta_de_volta(): void
    {
        $f = Flow::create(['account_id' => $this->account->id, 'name' => 'Ciclo', 'enabled' => false, 'timeout_seconds' => 600]);
        $raiz = FlowNode::create(['flow_id' => $f->id, 'kind' => 'menu', 'message' => 'RAIZ']);
        $filho = FlowNode::create(['flow_id' => $f->id, 'kind' => 'menu', 'message' => 'FILHO']);
        $f->update(['root_node_id' => $raiz->id]);
        FlowOption::create(['flow_node_id' => $raiz->id, 'input' => '1', 'label' => 'Ir', 'next_node_id' => $filho->id, 'ordem' => 1]);
        FlowOption::create(['flow_node_id' => $filho->id, 'input' => '0', 'label' => 'Voltar', 'next_node_id' => $raiz->id, 'ordem' => 1]);

        $dsl = $this->build($f);

        // Declaracao UNICA da raiz (uma linha de shape)...
        $this->assertSame(1, substr_count($dsl, "n{$raiz->id}{\""));
        // ...e a aresta de VOLTA existe (o laco e so uma aresta a mais).
        $this->assertStringContainsString("n{$filho->id} -->|\"0 - Voltar\"| n{$raiz->id}", $dsl);
    }

    public function test_orfao_e_declarado_sem_arestas(): void
    {
        $f = Flow::create(['account_id' => $this->account->id, 'name' => 'Orfao', 'enabled' => false, 'timeout_seconds' => 600]);
        $raiz = FlowNode::create(['flow_id' => $f->id, 'kind' => 'menu', 'message' => 'RAIZ']);
        $f->update(['root_node_id' => $raiz->id]);
        $orfao = FlowNode::create(['flow_id' => $f->id, 'kind' => 'final', 'message' => 'SOLTO']);

        $dsl = $this->build($f);

        $this->assertStringContainsString("n{$orfao->id}([\"", $dsl);          // declarado
        $this->assertStringNotContainsString("| n{$orfao->id}", $dsl);         // sem aresta chegando
        $this->assertStringNotContainsString("n{$orfao->id} -->", $dsl);       // sem aresta saindo
    }

    public function test_sanitizacao_texto_de_usuario_nao_injeta_sintaxe(): void
    {
        $f = Flow::create(['account_id' => $this->account->id, 'name' => 'Inj', 'enabled' => false, 'timeout_seconds' => 600]);
        $raiz = FlowNode::create([
            'flow_id' => $f->id, 'kind' => 'menu',
            'message' => "Oi \"cliente\" [x] {y} |z|\n<script>`s`; fim",
        ]);
        $f->update(['root_node_id' => $raiz->id]);

        $dsl = $this->build($f);

        // Nenhum caractere de sintaxe do usuario sobrevive dentro do label.
        $linhaShape = collect(explode("\n", $dsl))->first(fn ($l) => str_contains($l, "n{$raiz->id}{"));
        $this->assertNotNull($linhaShape);
        $miolo = \Illuminate\Support\Str::between($linhaShape, '{"', '"}');
        foreach (['"', '[', ']', '{', '}', '|', '<', '>', '`', ';', "\n"] as $perigoso) {
            $this->assertStringNotContainsString($perigoso, $miolo, "caractere '{$perigoso}' vazou pro label");
        }
        $this->assertStringContainsString('Oi', $miolo); // o texto util permanece

        // Truncamento ~60 aplicado (mensagem longa nao infla o grafo).
        $raiz->update(['message' => str_repeat('palavra ', 30)]);
        $dsl2 = $this->build($f->fresh());
        $linha2 = collect(explode("\n", $dsl2))->first(fn ($l) => str_contains($l, "n{$raiz->id}{"));
        $this->assertLessThan(90, mb_strlen(\Illuminate\Support\Str::between($linha2, '{"', '"}')));
    }

    public function test_cores_hex_seguem_o_ciclo_de_12_da_fatia_17(): void
    {
        $f = Flow::create(['account_id' => $this->account->id, 'name' => 'Cores', 'enabled' => false, 'timeout_seconds' => 600]);
        $nos = [];
        foreach (range(1, 13) as $i) {
            $nos[$i] = FlowNode::create(['flow_id' => $f->id, 'kind' => 'final', 'message' => "N{$i}"]);
        }
        $f->update(['root_node_id' => $nos[1]->id]);

        $dsl = $this->build($f);

        // No #1 e no #13 com o MESMO hex (ciclo de 12), igual ao helper.
        $this->assertSame(FlowNode::IDENTITY_HEX[0], $nos[1]->identityHex());
        $this->assertSame($nos[1]->identityHex(), $nos[13]->identityHex());
        $this->assertStringContainsString("style n{$nos[1]->id} fill:transparent,stroke:" . FlowNode::IDENTITY_HEX[0], $dsl);
        $this->assertStringContainsString("style n{$nos[13]->id} fill:transparent,stroke:" . FlowNode::IDENTITY_HEX[0], $dsl);
    }

    public function test_isolamento_so_emite_nos_do_proprio_fluxo(): void
    {
        $outro = Flow::create(['account_id' => $this->account->id, 'name' => 'Outro', 'enabled' => false, 'timeout_seconds' => 600]);
        $noOutro = FlowNode::create(['flow_id' => $outro->id, 'kind' => 'final', 'message' => 'DE-OUTRO-FLUXO']);

        $f = Flow::create(['account_id' => $this->account->id, 'name' => 'Meu', 'enabled' => false, 'timeout_seconds' => 600]);
        $raiz = FlowNode::create(['flow_id' => $f->id, 'kind' => 'menu', 'message' => 'MEU']);
        $f->update(['root_node_id' => $raiz->id]);
        // Opcao apontando pra no de OUTRO fluxo (corrompido): sem aresta.
        FlowOption::create(['flow_node_id' => $raiz->id, 'input' => '1', 'label' => 'X', 'next_node_id' => $noOutro->id, 'ordem' => 1]);

        $dsl = $this->build($f);

        $this->assertStringNotContainsString('DE-OUTRO-FLUXO', $dsl);
        $this->assertStringNotContainsString("n{$noOutro->id}", $dsl);
    }
}
