<?php

namespace App\Livewire;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\FlowOption;
use App\Whatsapp\AutoReply\RuleMatcher;
use App\Whatsapp\Secrets\SecretVault;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Fatia B — construtor de fluxos (menus condicionais). Edita DIRETO no banco por acao
 * (criar nó, adicionar opcao, definir destino) — robusto pra arvore, sem array gigante
 * no componente. So construcao/UI; o motor (FlowEngine) e a runtime nao mudam aqui.
 */
#[Layout('components.layouts.app')]
class Fluxos extends Component
{
    public ?int $editingFlowId = null;

    // Config do fluxo (modo edicao).
    public string $name = '';
    public bool $enabled = false;
    public string $scope = 'global';
    public int $timeout_seconds = 600;
    public string $invalid_message = '';
    /** @var array<int,array{type:string,value:string,precision:string,fuzzy_level:?string}> */
    public array $triggers = [];
    /** @var array<int,int> */
    public array $scopeContactIds = [];
    public string $scopeSearch = '';

    // Buffers de edicao da arvore (por id).
    /** @var array<int,string> */
    public array $nodeMsg = [];
    /** @var array<int,string> */
    public array $nodeKind = [];
    /** @var array<int,array{input:string,label:string}> */
    public array $optBuf = [];

    public ?int $confirmingDeleteFlowId = null;

    private function accountId(): int
    {
        return (int) (Account::query()->oldest('id')->value('id')
            ?? Account::create(['name' => config('app.name', 'msgautomation')])->id);
    }

    private function flow(): ?Flow
    {
        return $this->editingFlowId
            ? Flow::query()->where('account_id', $this->accountId())->with(['triggers', 'contacts'])->find($this->editingFlowId)
            : null;
    }

    // ---- Lista --------------------------------------------------------------

    public function novoFluxo(): void
    {
        $flow = Flow::create([
            'account_id' => $this->accountId(), 'name' => 'Novo fluxo', 'enabled' => false,
            'scope' => 'global', 'timeout_seconds' => 600,
        ]);
        $root = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'menu', 'message' => 'Ola! Escolha uma opcao:', 'ordem' => 0]);
        $flow->update(['root_node_id' => $root->id]);

        $this->editar($flow->id);
    }

    public function editar(int $flowId): void
    {
        $flow = Flow::query()->where('account_id', $this->accountId())->with(['triggers', 'contacts'])->findOrFail($flowId);
        $this->editingFlowId = $flow->id;
        $this->name = (string) $flow->name;
        $this->enabled = (bool) $flow->enabled;
        $this->scope = $flow->scope ?: 'global';
        $this->timeout_seconds = (int) $flow->timeout_seconds;
        $this->invalid_message = (string) $flow->invalid_message;
        $this->triggers = $flow->triggerList()->map(fn ($t) => [
            'type' => $t['type'], 'value' => $t['value'], 'precision' => $t['precision'] ?? 'exato', 'fuzzy_level' => $t['fuzzy_level'] ?? 'media',
        ])->values()->all();
        if ($this->triggers === []) {
            $this->triggers = [['type' => 'contains', 'value' => '', 'precision' => 'exato', 'fuzzy_level' => 'media']];
        }
        $this->scopeContactIds = $flow->contacts->pluck('id')->all();
        $this->scopeSearch = '';
        $this->loadNodeBuffers($flow);
        $this->resetValidation();
    }

    public function voltar(): void
    {
        $this->editingFlowId = null;
    }

    public function toggleFluxo(int $id): void
    {
        $flow = Flow::query()->where('account_id', $this->accountId())->with('triggers')->find($id);
        if (! $flow) {
            return;
        }
        if (! $flow->enabled) {
            // Validacao pra LIGAR: precisa de gatilho de entrada e nó raiz.
            if ($flow->triggers->isEmpty() || $flow->root_node_id === null) {
                $this->dispatch('toast', message: 'Para ligar, o fluxo precisa de ao menos um gatilho de entrada e um nó raiz.', type: 'error');

                return;
            }
        }
        $flow->update(['enabled' => ! $flow->enabled]);
        $this->dispatch('toast', message: $flow->enabled ? 'Fluxo ligado.' : 'Fluxo desligado.');
    }

    public function confirmDeleteFlow(int $id): void
    {
        $this->confirmingDeleteFlowId = $id;
    }

    public function cancelDeleteFlow(): void
    {
        $this->confirmingDeleteFlowId = null;
    }

    public function deleteFlowConfirmed(): void
    {
        if ($this->confirmingDeleteFlowId) {
            // Cascade apaga triggers/nodes/options/sessions (FKs).
            Flow::query()->where('account_id', $this->accountId())->where('id', $this->confirmingDeleteFlowId)->delete();
            if ($this->editingFlowId === $this->confirmingDeleteFlowId) {
                $this->editingFlowId = null;
            }
            $this->dispatch('toast', message: 'Fluxo excluido.');
        }
        $this->confirmingDeleteFlowId = null;
    }

    // ---- Config + gatilhos --------------------------------------------------

    public function addTrigger(): void
    {
        $this->triggers[] = ['type' => 'contains', 'value' => '', 'precision' => 'exato', 'fuzzy_level' => 'media'];
    }

    public function removeTrigger(int $i): void
    {
        if (count($this->triggers) > 1) {
            unset($this->triggers[$i]);
            $this->triggers = array_values($this->triggers);
        }
    }

    public function salvarConfig(): void
    {
        $flow = $this->flow();
        if (! $flow) {
            return;
        }

        $this->validate([
            'name' => 'required|string|max:120',
            'scope' => 'required|in:global,contatos',
            'timeout_seconds' => 'required|integer|min:60|max:86400',
            'invalid_message' => 'nullable|string|max:500',
            'triggers' => 'required|array|min:1',
            'triggers.*.type' => 'required|in:exact,contains,starts_with,regex',
            'triggers.*.value' => 'required|string|max:255',
        ]);

        foreach ($this->triggers as $i => $t) {
            if ($t['type'] === 'regex' && ! RuleMatcher::isValidRegex((string) $t['value'])) {
                $this->addError("triggers.{$i}.value", 'Regex invalido.');

                return;
            }
        }

        $scope = $this->scope === 'contatos' ? 'contatos' : 'global';
        $contactIds = [];
        if ($scope === 'contatos') {
            $contactIds = Contact::query()->where('account_id', $this->accountId())->whereIn('id', $this->scopeContactIds)->pluck('id')->all();
            if ($contactIds === []) {
                $this->addError('scopeContactIds', 'Escopo "contatos": selecione ao menos um contato.');

                return;
            }
        }

        $flow->update([
            'name' => trim($this->name),
            'scope' => $scope,
            'timeout_seconds' => $this->timeout_seconds,
            'invalid_message' => trim($this->invalid_message) !== '' ? trim($this->invalid_message) : null,
        ]);

        $flow->triggers()->delete();
        $flow->triggers()->createMany(array_map(fn ($t) => [
            'match_type' => $t['type'],
            'match_value' => trim((string) $t['value']),
            'precision' => $t['type'] !== 'regex' && ($t['precision'] ?? 'exato') === 'tolerante' ? 'tolerante' : 'exato',
            'fuzzy_level' => ($t['type'] !== 'regex' && ($t['precision'] ?? 'exato') === 'tolerante') ? ($t['fuzzy_level'] ?? 'media') : null,
        ], $this->triggers));
        $flow->contacts()->sync($contactIds);

        $this->dispatch('toast', message: 'Configuracao do fluxo salva.');
    }

    // ---- Arvore: nós e opcoes ----------------------------------------------

    private function loadNodeBuffers(Flow $flow): void
    {
        $this->nodeMsg = [];
        $this->nodeKind = [];
        $this->optBuf = [];
        foreach ($flow->nodes()->with('options')->get() as $node) {
            $this->nodeMsg[$node->id] = (string) $node->message;
            $this->nodeKind[$node->id] = $node->kind;
            foreach ($node->options as $opt) {
                $this->optBuf[$opt->id] = ['input' => (string) $opt->input, 'label' => (string) $opt->label];
            }
        }
    }

    private function ownNode(int $nodeId): ?FlowNode
    {
        return FlowNode::query()->where('id', $nodeId)->whereHas('flow', fn ($q) => $q->where('account_id', $this->accountId()))->first();
    }

    public function inserirSenhaNo(int $nodeId, string $nome): void
    {
        if (! isset($this->nodeMsg[$nodeId])) {
            return;
        }
        $atual = trim((string) $this->nodeMsg[$nodeId]);
        $ref = '{senha:' . $nome . '}';
        $this->nodeMsg[$nodeId] = $atual === '' ? $ref : $atual . ' ' . $ref;
    }

    public function salvarNo(int $nodeId): void
    {
        $node = $this->ownNode($nodeId);
        if (! $node) {
            return;
        }
        $kind = ($this->nodeKind[$nodeId] ?? 'menu') === 'final' ? 'final' : 'menu';
        $node->update(['message' => (string) ($this->nodeMsg[$nodeId] ?? ''), 'kind' => $kind]);
        $this->dispatch('toast', message: 'No salvo.');
    }

    public function addOpcao(int $nodeId): void
    {
        $node = $this->ownNode($nodeId);
        if (! $node) {
            return;
        }
        $prox = (int) ($node->options()->max('ordem') ?? 0) + 1;
        $opt = $node->options()->create(['input' => (string) $prox, 'label' => '', 'next_node_id' => null, 'ordem' => $prox]);
        $this->optBuf[$opt->id] = ['input' => (string) $prox, 'label' => ''];
    }

    public function salvarOpcao(int $optId): void
    {
        $opt = $this->ownOption($optId);
        if (! $opt) {
            return;
        }
        $opt->update([
            'input' => trim((string) ($this->optBuf[$optId]['input'] ?? '')),
            'label' => trim((string) ($this->optBuf[$optId]['label'] ?? '')),
        ]);
        $this->dispatch('toast', message: 'Opcao salva.');
    }

    public function removerOpcao(int $optId): void
    {
        $opt = $this->ownOption($optId);
        if ($opt) {
            $opt->delete();
            unset($this->optBuf[$optId]);
        }
    }

    /**
     * Destino da opcao: id de nó existente, ou 'novo_menu'/'novo_final' (cria nó filho
     * e liga). Vazio limpa o destino.
     */
    public function definirDestino(int $optId, string $valor): void
    {
        $opt = $this->ownOption($optId);
        if (! $opt) {
            return;
        }
        $node = $this->ownNode((int) $opt->flow_node_id);
        if (! $node) {
            return;
        }

        if ($valor === 'novo_menu' || $valor === 'novo_final') {
            $novo = FlowNode::create([
                'flow_id' => $node->flow_id,
                'parent_node_id' => $node->id,
                'kind' => $valor === 'novo_final' ? 'final' : 'menu',
                'message' => $valor === 'novo_final' ? 'Resposta final...' : 'Sub-menu...',
                'ordem' => (int) (FlowNode::where('flow_id', $node->flow_id)->max('ordem') ?? 0) + 1,
            ]);
            $this->nodeMsg[$novo->id] = (string) $novo->message;
            $this->nodeKind[$novo->id] = $novo->kind;
            $opt->update(['next_node_id' => $novo->id]);

            return;
        }

        $destId = (int) $valor;
        // So aceita nós do mesmo fluxo (evita ligar a outro fluxo).
        $valido = $destId > 0 && FlowNode::where('id', $destId)->where('flow_id', $node->flow_id)->exists();
        $opt->update(['next_node_id' => $valido ? $destId : null]);
    }

    public function addNoRaizFilho(): void
    {
        // Atalho: adiciona uma opcao no root ja com um novo nó final.
        $flow = $this->flow();
        if ($flow && $flow->root_node_id) {
            $this->addOpcao((int) $flow->root_node_id);
        }
    }

    public function removerNo(int $nodeId): void
    {
        $node = $this->ownNode($nodeId);
        if (! $node) {
            return;
        }
        $flow = $node->flow;
        if ($flow && (int) $flow->root_node_id === (int) $nodeId) {
            $this->dispatch('toast', message: 'Nao da pra excluir o nó raiz.', type: 'error');

            return;
        }
        // Opcoes que apontavam pra ele perdem o destino.
        FlowOption::where('next_node_id', $nodeId)->update(['next_node_id' => null]);
        $node->options()->delete();
        $node->delete();
        unset($this->nodeMsg[$nodeId], $this->nodeKind[$nodeId]);
    }

    private function ownOption(int $optId): ?FlowOption
    {
        return FlowOption::query()->where('id', $optId)
            ->whereHas('node.flow', fn ($q) => $q->where('account_id', $this->accountId()))
            ->first();
    }

    /** Nós ordenados em arvore (raiz primeiro, depois filhos por ordem) com profundidade. */
    private function treeOrdered(Flow $flow): array
    {
        $nodes = $flow->nodes()->with('options')->orderBy('ordem')->orderBy('id')->get();
        $byParent = $nodes->groupBy(fn ($n) => $n->parent_node_id ?? 0);
        $out = [];
        $walk = function ($parentId, $depth) use (&$walk, &$out, $byParent) {
            foreach ($byParent->get($parentId, collect()) as $node) {
                $out[] = ['node' => $node, 'depth' => $depth];
                $walk($node->id, $depth + 1);
            }
        };
        // raiz = nós sem parent (0). Garante a raiz do flow primeiro.
        $walk(0, 0);

        return $out;
    }

    public function render()
    {
        $accountId = $this->accountId();
        $flows = Flow::query()->where('account_id', $accountId)->withCount('nodes')->with('triggers')->orderBy('id')->get();

        $flow = $this->flow();
        $tree = $flow ? $this->treeOrdered($flow) : [];
        $deleting = $this->confirmingDeleteFlowId ? $flows->firstWhere('id', $this->confirmingDeleteFlowId) : null;

        $contacts = ($flow && $this->scope === 'contatos')
            ? Contact::query()->where('account_id', $accountId)
                ->when($this->scopeSearch !== '', fn ($q) => $q->where(fn ($w) => $w->where('push_name', 'like', '%' . $this->scopeSearch . '%')->orWhere('remote_jid', 'like', '%' . $this->scopeSearch . '%')))
                ->orderByRaw('COALESCE(push_name, remote_jid)')->limit(500)->get(['id', 'push_name', 'remote_jid'])
            : collect();

        $secretNames = $flow ? app(SecretVault::class)->names($accountId) : [];

        return view('livewire.fluxos', [
            'flows' => $flows,
            'flow' => $flow,
            'tree' => $tree,
            'deleting' => $deleting,
            'contacts' => $contacts,
            'secretNames' => $secretNames,
        ]);
    }
}
