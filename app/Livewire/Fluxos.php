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
    /** @var array<int,int> T-1: tags do escopo 'tags' */
    public array $scopeTagIds = [];

    // Buffers de edicao da arvore (por id).
    /** @var array<int,string> */
    public array $nodeMsg = [];
    /** @var array<int,string> */
    public array $nodeKind = [];
    /** @var array<int,array{input:string,label:string}> */
    public array $optBuf = [];

    public ?int $confirmingDeleteFlowId = null;

    // C.1 — testador (dry-run, sem enviar, sem persistir sessao).
    public bool $simOpen = false;
    public ?int $simNodeId = null;
    public string $simStatus = 'none';
    public string $simInput = '';
    public bool $simReveal = false;
    /** @var array<int,array{who:string,raw:string}> */
    public array $simLog = [];

    private function accountId(): int
    {
        // MT-0: conta do CONTEXTO (fase 1 = conta unica, fallback centralizado).
        return app(\App\Tenancy\AccountContext::class)->id();
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

    /**
     * Fatia 7 — cria um fluxo REAL a partir de um template do catalogo (escopado
     * a conta ativa) e ja abre no editor pro usuario revisar/ajustar.
     */
    public function usarTemplate(string $key): void
    {
        try {
            $flow = app(\App\Whatsapp\Flows\InstantiateFlowTemplate::class)->handle($key, $this->accountId());
        } catch (\InvalidArgumentException) {
            $this->dispatch('toast', message: 'Modelo de fluxo desconhecido.', type: 'error');

            return;
        }

        $this->editar($flow->id);
        $this->dispatch('toast', message: 'Fluxo criado a partir do modelo — revise as mensagens e ajuste como quiser.');
    }

    /**
     * Fatia 13 — duplicar fluxo (deep copy remapeado, enabled=false, nome
     * sufixado) e abrir a COPIA no editor (mesmo redirect do usarTemplate).
     * Posse dupla: find escopado aqui + firstOrFail por conta no servico.
     */
    public function duplicar(int $flowId): void
    {
        if (! Flow::query()->where('account_id', $this->accountId())->whereKey($flowId)->exists()) {
            $this->dispatch('toast', message: 'Fluxo nao encontrado.', type: 'error');

            return;
        }

        try {
            $copia = app(\App\Whatsapp\Flows\DuplicateFlow::class)->handle($flowId, $this->accountId());
        } catch (\RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->editar($copia->id);
        $this->dispatch('toast', message: 'Fluxo duplicado (desligado) — edite e ligue quando quiser.');
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
        $this->scopeTagIds = $flow->tags()->pluck('tags.id')->all();
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
            // Guarda de segredo (como nas regras): nó com {senha:...} exige escopo contatos
            // E gatilhos de entrada ESTRITOS (sem fuzzy) — pra nao disparar/vazar por engano.
            $vault = app(SecretVault::class);
            $temSenha = $flow->nodes()->get()->contains(fn ($n) => $vault->hasRef((string) $n->message));
            if ($temSenha) {
                if (($flow->scope ?: 'global') !== 'contatos') {
                    $this->dispatch('toast', message: 'Este fluxo usa senha ({senha:...}) em um nó. Use escopo "Contatos Especificos" antes de ligar (tag/global nao valem: a senha vazaria pra quem disparar).', type: 'error');

                    return;
                }
                if ($flow->triggers->contains(fn ($t) => $t->precision === 'tolerante')) {
                    $this->dispatch('toast', message: 'Fluxo com senha exige gatilho de entrada ESTRITO (exato). Tire a tolerancia a erros do gatilho antes de ligar.', type: 'error');

                    return;
                }
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
            'scope' => 'required|in:global,contatos,tags',
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

        $scope = in_array($this->scope, ['contatos', 'tags'], true) ? $this->scope : 'global';
        $contactIds = [];
        if ($scope === 'contatos') {
            $contactIds = Contact::query()->where('account_id', $this->accountId())->whereIn('id', $this->scopeContactIds)->pluck('id')->all();
            if ($contactIds === []) {
                $this->addError('scopeContactIds', 'Escopo "contatos": selecione ao menos um contato.');

                return;
            }
        }

        // T-1: escopo por tag (entra quem tem QUALQUER uma). A guarda de segredo do
        // toggleFluxo segue exigindo 'contatos' pra fluxo com {senha:} — tag nunca.
        $tagIds = [];
        if ($scope === 'tags') {
            $tagIds = \App\Models\Tag::query()->whereIn('id', $this->scopeTagIds)->pluck('id')->all();
            if ($tagIds === []) {
                $this->addError('scopeTagIds', 'Escopo "tag": selecione ao menos uma tag.');

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
        $flow->tags()->sync($tagIds);

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

    /**
     * Fatia 15 — insere {kb:slug} na mensagem do no (mesmo padrao do
     * inserirSenhaNo: no FIM do campo — escolha registrada; cursor exigiria JS
     * fora do padrao do editor). So slugs REFERENCIAVEIS da conta ativa.
     */
    public function inserirConhecimentoNo(int $nodeId, string $slug): void
    {
        if (! isset($this->nodeMsg[$nodeId])) {
            return;
        }
        // Posse/elegibilidade server-side (acao Livewire e forjavel).
        if (! \App\Models\Knowledge::query()->referenciavel($this->accountId())->where('slug', $slug)->exists()) {
            return;
        }
        $atual = trim((string) $this->nodeMsg[$nodeId]);
        $ref = '{kb:' . $slug . '}';
        $this->nodeMsg[$nodeId] = $atual === '' ? $ref : $atual . ' ' . $ref;
    }

    public function salvarNo(int $nodeId): void
    {
        $node = $this->ownNode($nodeId);
        if (! $node) {
            return;
        }
        $kindBuf = (string) ($this->nodeKind[$nodeId] ?? 'menu');
        $kind = in_array($kindBuf, ['final', 'handoff'], true) ? $kindBuf : 'menu';
        $mensagem = (string) ($this->nodeMsg[$nodeId] ?? '');

        // Fatia 5b — handoff e TERMINAL (encerra e chama humano): nao pode ter opcoes,
        // e a mensagem (a despedida enviada ao contato) e obrigatoria.
        if ($kind === 'handoff') {
            if ($node->options()->exists()) {
                $this->nodeKind[$nodeId] = $node->kind; // reverte o select — o no NAO virou handoff
                $this->dispatch('toast', message: 'Handoff e terminal: remova as opcoes deste no antes de troca-lo pra handoff.', type: 'error');

                return;
            }
            if (trim($mensagem) === '') {
                $this->dispatch('toast', message: 'Handoff exige mensagem (e o aviso enviado ao contato, ex.: "Um atendente vai te responder em breve").', type: 'error');

                return;
            }
        }
        $node->update(['message' => $mensagem, 'kind' => $kind]);

        // V-1 — AVISO: placeholder desconhecido sairia CRU pro contato.
        $desconhecidas = \App\Models\Variable::unknownRefs($this->accountId(), $mensagem);
        if ($desconhecidas !== []) {
            $this->dispatch('toast', message: 'Aviso: referencia(s) desconhecida(s) no no: {' . implode('}, {', $desconhecidas) . '} — sem variavel ativa, sai cru.', type: 'error');
        }
        $this->dispatch('toast', message: 'No salvo.');
    }

    public function addOpcao(int $nodeId): void
    {
        $node = $this->ownNode($nodeId);
        if (! $node) {
            return;
        }
        // Fatia 5b — handoff e terminal: sem opcoes (guarda server-side; a UI ja esconde).
        if ($node->isHandoff()) {
            $this->dispatch('toast', message: 'Handoff e terminal: nao aceita opcoes.', type: 'error');

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
     * Destino da opcao: id de nó existente, ou 'novo_menu'/'novo_final'/'novo_handoff'
     * (cria nó filho e liga). Vazio limpa o destino.
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

        if (in_array($valor, ['novo_menu', 'novo_final', 'novo_handoff'], true)) {
            $novo = FlowNode::create([
                'flow_id' => $node->flow_id,
                'parent_node_id' => $node->id,
                'kind' => match ($valor) { 'novo_final' => 'final', 'novo_handoff' => 'handoff', default => 'menu' },
                'message' => match ($valor) {
                    'novo_final' => 'Resposta final...',
                    'novo_handoff' => 'Um atendente vai te responder em breve.',
                    default => 'Sub-menu...',
                },
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

    // ---- C.1: testador (dry-run) -------------------------------------------

    public function iniciarSim(\App\Whatsapp\Flows\FlowEngine $engine): void
    {
        $flow = $this->flow();
        if (! $flow) {
            return;
        }
        $this->simOpen = true;
        $this->simLog = [];
        $this->simInput = '';
        $r = $engine->simStart($flow);
        $this->simNodeId = $r['node_id'];
        $this->simStatus = $r['status'];
        if ($r['text'] !== null) {
            $this->simLog[] = ['who' => 'bot', 'raw' => (string) $r['text']];
        }
    }

    public function enviarSim(\App\Whatsapp\Flows\FlowEngine $engine): void
    {
        $flow = $this->flow();
        $texto = trim($this->simInput);
        if (! $flow || $texto === '') {
            return;
        }
        $this->simLog[] = ['who' => 'user', 'raw' => $texto];

        // Sessao encerrada? Um novo texto pode reiniciar pelo gatilho (como na runtime).
        $r = in_array($this->simStatus, ['completed', 'cancelled', 'none'], true)
            ? ($engine->entryFlow($this->accountId(), $texto, 'sim@local') ? $engine->simStart($flow) : ['node_id' => $this->simNodeId, 'text' => null, 'status' => $this->simStatus])
            : $engine->simAdvance($flow, $this->simNodeId, $texto);

        $this->simNodeId = $r['node_id'];
        $this->simStatus = $r['status'];
        if (($r['text'] ?? null) !== null && $r['text'] !== null) {
            $this->simLog[] = ['who' => 'bot', 'raw' => (string) $r['text']];
        } else {
            $this->simLog[] = ['who' => 'bot', 'raw' => '(sem resposta — fora do fluxo)'];
        }
        $this->simInput = '';
    }

    public function toggleSimReveal(): void
    {
        $this->simReveal = ! $this->simReveal;
    }

    public function fecharSim(): void
    {
        $this->simOpen = false;
        $this->simLog = [];
        $this->simNodeId = null;
        $this->simStatus = 'none';
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

        // Fatia 9 — badge "Padrao": qual fluxo e o default_flow_id da conta ativa
        // (leitura apenas; a escrita segue em Configuracoes e no modal de ativacao).
        $defaultFlowId = \App\Models\AutoReplySetting::query()->where('account_id', $accountId)->value('default_flow_id');

        $flow = $this->flow();
        $tree = $flow ? $this->treeOrdered($flow) : [];
        $warnings = $flow ? $this->flowWarnings($flow, $tree) : [];
        $deleting = $this->confirmingDeleteFlowId ? $flows->firstWhere('id', $this->confirmingDeleteFlowId) : null;

        $contacts = ($flow && $this->scope === 'contatos')
            ? Contact::query()->where('account_id', $accountId)
                ->when($this->scopeSearch !== '', fn ($q) => $q->where(fn ($w) => $w->where('push_name', 'like', '%' . $this->scopeSearch . '%')->orWhere('remote_jid', 'like', '%' . $this->scopeSearch . '%')))
                ->orderByRaw('COALESCE(push_name, remote_jid)')->limit(500)->get(['id', 'push_name', 'remote_jid'])
            : collect();

        $secretNames = $flow ? app(SecretVault::class)->names($accountId) : [];

        // Fatia 15 — conhecimentos REFERENCIAVEIS ({kb:slug}) pro dropdown do
        // editor: ativos, 'low', sem restricao de contatos e sem {senha:} no
        // conteudo (mesma elegibilidade do resolver — o dropdown nunca oferece
        // o que nao resolveria no envio).
        $kbOptions = collect();
        if ($flow) {
            $vault = app(SecretVault::class);
            $kbOptions = \App\Models\Knowledge::query()->referenciavel($accountId)
                ->orderBy('title')->get(['slug', 'title', 'content'])
                ->filter(fn ($k) => ! $vault->hasRef((string) $k->content))
                ->values();
        }

        // C.2 — sobreposicao fluxo (entrada) × regra (o fluxo vence; aviso na lista).
        $flowConflicts = $this->editingFlowId ? [] : app(\App\Whatsapp\AutoReply\RuleConflictDetector::class)->flowRuleOverlaps($accountId)['flows'];

        // Fatia 7 — catalogo de templates (so na lista; instanciar abre o editor).
        $templates = $this->editingFlowId ? [] : app(\App\Whatsapp\Flows\FlowTemplateCatalog::class)->summaries();

        // C.1 — transcript do testador renderizado: placeholders + senha mascarada (ou revelada).
        $vault = app(SecretVault::class);
        $responder = app(\App\Whatsapp\AutoReply\RuleResponder::class);
        $simView = [];
        foreach ($this->simLog as $e) {
            if ($e['who'] === 'user') {
                $simView[] = ['who' => 'user', 'text' => $e['raw'], 'secret' => false];

                continue;
            }
            $rendered = $responder->render($e['raw'], ['now' => now()]);
            $secret = $vault->hasRef($rendered);
            if ($secret && $this->simReveal) {
                try {
                    $text = $vault->resolve($accountId, $rendered);
                } catch (\Throwable) {
                    $text = $vault->mask($rendered);
                }
            } else {
                $text = $vault->mask($rendered);
            }
            $simView[] = ['who' => 'bot', 'text' => $text, 'secret' => $secret];
        }

        return view('livewire.fluxos', [
            'flows' => $flows,
            'defaultFlowId' => $defaultFlowId !== null ? (int) $defaultFlowId : null,
            'flow' => $flow,
            'tree' => $tree,
            'deleting' => $deleting,
            'contacts' => $contacts,
            'secretNames' => $secretNames,
            'kbOptions' => $kbOptions,
            'simView' => $simView,
            'flowConflicts' => $flowConflicts,
            'warnings' => $warnings,
            'templates' => $templates,
        ]);
    }

    /** C.3 — avisos de arvore mal-montada (so exibicao; nao bloqueia salvar). */
    private function flowWarnings(Flow $flow, array $tree): array
    {
        $w = [];
        if ($flow->triggers()->count() === 0) {
            $w[] = 'Sem gatilho de entrada — o fluxo nao pode ser ligado.';
        }
        foreach ($tree as $row) {
            $node = $row['node'];
            $opts = $node->options;
            // Fatia 5b: handoff e terminal por natureza — sem opcao NAO e problema.
            if (! in_array($node->kind, ['final', 'handoff'], true) && $opts->isEmpty()) {
                $w[] = "No #{$node->id} e menu mas nao tem opcao — vai encerrar como resposta final.";
            }
            if ($node->kind === 'handoff' && $opts->isNotEmpty()) {
                $w[] = "No #{$node->id} e handoff (terminal) mas tem opcoes — elas sao ignoradas na execucao.";
            }
            if ($node->kind === 'handoff' && trim((string) $node->message) === '') {
                $w[] = "No #{$node->id} e handoff sem mensagem — o contato seria passado pro humano sem aviso.";
            }
            foreach ($opts as $opt) {
                if (! $opt->next_node_id) {
                    $w[] = "Opcao \"{$opt->input}\" do no #{$node->id} esta sem destino.";
                }
                if (trim((string) $opt->label) === '') {
                    $w[] = "Opcao \"{$opt->input}\" do no #{$node->id} esta sem rotulo.";
                }
            }
        }

        return $w;
    }
}
