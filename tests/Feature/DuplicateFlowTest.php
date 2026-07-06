<?php

namespace Tests\Feature;

use App\Livewire\Fluxos;
use App\Models\Account;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\FlowOption;
use App\Models\FlowSession;
use App\Models\AutoReplySetting;
use App\Tenancy\AccountContext;
use App\Whatsapp\Flows\DuplicateFlow;
use App\Whatsapp\Flows\InstantiateFlowTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fatia 13 — duplicar fluxo (deep copy): Flow + nos + opcoes + gatilhos com
 * remapeamento COMPLETO (root/parents/destinos) — nenhuma referencia da copia
 * aponta pra no do original. Copia nasce enabled=false com nome sufixado
 * (mecanismo da Fatia 7); original byte-identico; default_flow_id e sessoes
 * intocados; atomico; posse por conta. Fixture: template 'clinica' da Fatia 7
 * (menu raiz + opcoes + final + HANDOFF + gatilhos).
 */
class DuplicateFlowTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'A']);
        app(AccountContext::class)->set($this->account->id);
    }

    private function original(): Flow
    {
        return app(InstantiateFlowTemplate::class)->handle('clinica', $this->account->id);
    }

    public function test_deep_copy_completo_com_remapeamento_total(): void
    {
        $original = $this->original();
        $copia = app(DuplicateFlow::class)->handle($original->id, $this->account->id);

        // Contagens IGUAIS (nos, opcoes, gatilhos).
        $nosOrig = FlowNode::where('flow_id', $original->id)->get();
        $nosCopia = FlowNode::where('flow_id', $copia->id)->get();
        $this->assertSame($nosOrig->count(), $nosCopia->count());
        $optsOrig = FlowOption::whereIn('flow_node_id', $nosOrig->pluck('id'))->get();
        $optsCopia = FlowOption::whereIn('flow_node_id', $nosCopia->pluck('id'))->get();
        $this->assertSame($optsOrig->count(), $optsCopia->count());
        $this->assertSame($original->triggers()->count(), $copia->triggers()->count());

        // Ids TODOS novos (nenhuma intersecao entre nos da copia e do original).
        $idsOrig = $nosOrig->pluck('id')->all();
        $idsCopia = $nosCopia->pluck('id')->all();
        $this->assertSame([], array_intersect($idsOrig, $idsCopia));

        // root_node_id remapeado: pertence a COPIA e tem o mesmo kind da raiz original.
        $this->assertContains((int) $copia->root_node_id, $idsCopia);
        $this->assertSame(
            FlowNode::find($original->root_node_id)->kind,
            FlowNode::find($copia->root_node_id)->kind,
        );

        // parent_node_id de CADA no da copia remapeado (null ou no DA COPIA).
        foreach ($nosCopia as $no) {
            if ($no->parent_node_id !== null) {
                $this->assertContains((int) $no->parent_node_id, $idsCopia, "parent do no #{$no->id} aponta pra fora da copia");
            }
        }

        // O TESTE CRITICO: cada destino de opcao da copia aponta pra no DA COPIA
        // (flow_id do destino = copia) — nenhuma referencia ao original.
        foreach ($optsCopia as $opt) {
            if ($opt->next_node_id !== null) {
                $destino = FlowNode::find($opt->next_node_id);
                $this->assertSame($copia->id, (int) $destino->flow_id, "destino da opcao #{$opt->id} aponta pro fluxo original");
            }
        }

        // Handoff copiado com a mesma mensagem; gatilho com normalized_text vivo.
        $handoffOrig = $nosOrig->firstWhere('kind', 'handoff');
        $handoffCopia = $nosCopia->firstWhere('kind', 'handoff');
        $this->assertNotNull($handoffCopia);
        $this->assertSame($handoffOrig->message, $handoffCopia->message);
        $this->assertNotNull($copia->triggers()->first()->normalized_text);
    }

    public function test_copia_nasce_desligada_com_sufixo_incremental(): void
    {
        $original = $this->original(); // template nasce enabled=true

        $c1 = app(DuplicateFlow::class)->handle($original->id, $this->account->id);
        $c2 = app(DuplicateFlow::class)->handle($original->id, $this->account->id);

        $this->assertFalse((bool) $c1->enabled);
        $this->assertFalse((bool) $c2->enabled);
        $this->assertSame($original->name . ' (copia)', $c1->name);
        $this->assertSame($original->name . ' (copia) (2)', $c2->name);
    }

    public function test_original_byte_identico_default_flow_e_sessoes_intocados(): void
    {
        $original = $this->original();
        $settings = AutoReplySetting::create(['account_id' => $this->account->id, 'default_flow_id' => $original->id]);
        FlowSession::create([
            'account_id' => $this->account->id, 'flow_id' => $original->id,
            'remote_jid' => '5541999990000@s.whatsapp.net', 'current_node_id' => $original->root_node_id,
            'status' => 'active', 'started_at' => now(), 'last_activity_at' => now(), 'expires_at' => now()->addHour(),
        ]);

        // Snapshot COMPLETO do original antes (atributos + estrutura serializada).
        $snapshot = fn () => [
            Flow::find($original->id)->only(['name', 'enabled', 'scope', 'timeout_seconds', 'invalid_message', 'root_node_id']),
            FlowNode::where('flow_id', $original->id)->orderBy('id')->get(['id', 'parent_node_id', 'kind', 'message', 'ordem'])->toArray(),
            FlowOption::whereIn('flow_node_id', FlowNode::where('flow_id', $original->id)->pluck('id'))
                ->orderBy('id')->get(['id', 'flow_node_id', 'input', 'label', 'next_node_id', 'ordem'])->toArray(),
            $original->triggers()->orderBy('id')->get(['id', 'match_type', 'match_value', 'precision', 'fuzzy_level'])->toArray(),
        ];
        $antes = $snapshot();

        $copia = app(DuplicateFlow::class)->handle($original->id, $this->account->id);

        $this->assertSame($antes, $snapshot()); // original BYTE-IDENTICO
        $this->assertSame($original->id, (int) $settings->fresh()->default_flow_id); // badge fica no original
        $this->assertSame(1, FlowSession::withoutAccountScope()->count()); // runtime NAO copiado
        $this->assertSame(0, FlowSession::withoutAccountScope()->where('flow_id', $copia->id)->count());
    }

    public function test_duplicar_pela_ui_abre_a_copia_no_editor_incluindo_handoff(): void
    {
        $original = $this->original();

        $tela = Livewire::test(Fluxos::class)->call('duplicar', $original->id);

        $copia = Flow::query()->where('name', $original->name . ' (copia)')->firstOrFail();
        $tela->assertSet('editingFlowId', $copia->id) // redirect pro editor (padrao fatia 7)
            ->assertSet('enabled', false);
        // Editor carregou o no handoff da COPIA sem quebrar (shape da 5b).
        $handoff = FlowNode::where('flow_id', $copia->id)->where('kind', 'handoff')->firstOrFail();
        $tela->assertSet("nodeKind.{$handoff->id}", 'handoff')
            ->assertSet("nodeMsg.{$handoff->id}", $handoff->message);
    }

    public function test_posse_duplicar_fluxo_de_outra_conta_e_rejeitado_sem_efeito(): void
    {
        $b = Account::create(['name' => 'B']);
        $fluxoB = Flow::create(['account_id' => $b->id, 'name' => 'Da-B', 'enabled' => true, 'timeout_seconds' => 600]);
        $antes = Flow::withoutAccountScope()->count();

        // (a) Pela UI (contexto = conta A): find escopado falha -> toast, nada criado.
        Livewire::test(Fluxos::class)->call('duplicar', $fluxoB->id);
        $this->assertSame($antes, Flow::withoutAccountScope()->count());

        // (b) Direto no servico (defesa em profundidade): firstOrFail por conta.
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(DuplicateFlow::class)->handle($fluxoB->id, $this->account->id);
    }

    public function test_isolamento_duplicar_em_a_nao_cria_nada_em_b(): void
    {
        $b = Account::create(['name' => 'B']);
        $original = $this->original();

        app(DuplicateFlow::class)->handle($original->id, $this->account->id);

        $this->assertSame(0, Flow::withoutAccountScope()->where('account_id', $b->id)->count());
        $this->assertSame(2, Flow::withoutAccountScope()->where('account_id', $this->account->id)->count());
    }
}
