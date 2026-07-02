<?php

namespace App\Livewire;

use App\Models\Account;
use App\Models\AutoReplyRule;
use App\Models\Channel;
use App\Models\Contact;
use App\Whatsapp\AutoReply\RuleTester;
use App\Whatsapp\AutoReply\RuleWriter;
use App\Whatsapp\Secrets\SecretVault;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * S7 — CRUD de regras avancadas: multiplos gatilhos (incl. regex), multiplas
 * respostas (escolha aleatoria no envio) e placeholders. Persiste nas tabelas
 * filhas rule_triggers/rule_responses e mantem as colunas legadas de
 * auto_reply_rules como cache denormalizado (1o gatilho / 1a resposta).
 */
#[Layout('components.layouts.app')]
class Regras extends Component
{
    public bool $showForm = false;
    public ?int $editingId = null;
    public ?int $confirmingDeleteId = null;

    /** @var array<int,array{type:string,value:string}> */
    public array $triggers = [];
    /** @var array<int,string> */
    public array $responses = [];
    public bool $enabled = true;

    // S2 frequencia + S3 escopo.
    public string $cooldownMode = 'global';
    public int $cooldownMinutes = 60;
    public string $scope = 'global';
    /** @var array<int,int> */
    public array $scopeContactIds = [];
    public string $scopeSearch = '';
    /** @var array<int,int> T-1: tags do escopo 'tags' */
    public array $scopeTagIds = [];

    // Camada 3 (IA) — "deixe a IA casar mensagens parecidas" + frases-exemplo.
    public bool $aiMatchEnabled = false;
    /** @var array<int,string> */
    public array $aiExamples = [];

    // S4 — testador (dry-run).
    public bool $showTester = false;
    public string $testSample = '';
    public ?int $testContactId = null;
    public ?array $testResult = null;
    public bool $testReveal = false;

    protected function rules(): array
    {
        return [
            'triggers' => 'required|array|min:1',
            'triggers.*.type' => 'required|in:exact,contains,starts_with,regex',
            'triggers.*.value' => 'required|string|max:255',
            'responses' => 'required|array|min:1',
            'responses.*' => 'required|string|max:4000',
            'enabled' => 'boolean',
            'cooldownMode' => 'required|in:global,sempre,1x_dia,cada_n',
            'cooldownMinutes' => 'required_if:cooldownMode,cada_n|integer|min:1|max:100000',
            'scope' => 'required|in:global,contatos,tags',
            'scopeContactIds' => 'array',
            'scopeContactIds.*' => 'integer',
        ];
    }

    protected function messages(): array
    {
        return [
            'triggers.*.value.required' => 'Informe o gatilho.',
            'responses.*.required' => 'Informe a resposta.',
            'responses.required' => 'Cadastre ao menos uma resposta.',
            'triggers.required' => 'Cadastre ao menos um gatilho.',
        ];
    }

    public function novo(): void
    {
        $this->reset(['editingId']);
        $this->triggers = [['type' => 'contains', 'value' => '']];
        $this->responses = [''];
        $this->enabled = true;
        $this->cooldownMode = 'global';
        $this->cooldownMinutes = 60;
        $this->scope = 'global';
        $this->scopeContactIds = [];
        $this->scopeTagIds = [];
        $this->scopeSearch = '';
        $this->aiMatchEnabled = false;
        $this->aiExamples = [];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $rule = $this->query()->with(['triggers', 'responses', 'contacts', 'aiExamples'])->findOrFail($id);

        $this->editingId = $rule->id;
        $this->triggers = $rule->triggerList()
            ->map(fn ($t) => ['type' => $t['type'], 'value' => $t['value'], 'precision' => $t['precision'] ?? 'exato', 'fuzzy_level' => $t['fuzzy_level'] ?? 'media'])
            ->values()->all();
        $this->responses = $rule->responseList()->values()->all();

        if ($this->triggers === []) {
            $this->triggers = [['type' => 'contains', 'value' => '', 'precision' => 'exato', 'fuzzy_level' => 'media']];
        }
        if ($this->responses === []) {
            $this->responses = [''];
        }

        $this->enabled = (bool) $rule->enabled;
        $this->cooldownMode = $rule->cooldown_mode ?: 'global';
        $this->cooldownMinutes = (int) ($rule->cooldown_minutes ?: 60);
        $this->scope = $rule->scope ?: 'global';
        $this->scopeContactIds = $rule->contacts->pluck('id')->all();
        $this->scopeTagIds = $rule->tags()->pluck('tags.id')->all();
        $this->scopeSearch = '';
        $this->aiMatchEnabled = (bool) $rule->ai_match_enabled;
        $this->aiExamples = $rule->aiExampleList();
        $this->resetValidation();
        $this->showForm = true;
    }

    public function addAiExample(): void
    {
        $this->aiExamples[] = '';
    }

    public function removeAiExample(int $i): void
    {
        unset($this->aiExamples[$i]);
        $this->aiExamples = array_values($this->aiExamples);
    }

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

    public function addResponse(): void
    {
        $this->responses[] = '';
    }

    /** S3 — insere {senha:nome} na resposta indicada (guarda so a referencia). */
    public function insertSecret(int $i, string $nome): void
    {
        if (! isset($this->responses[$i])) {
            return;
        }
        $ref = '{senha:' . $nome . '}';
        $atual = trim((string) $this->responses[$i]);
        $this->responses[$i] = $atual === '' ? $ref : $atual . ' ' . $ref;
    }

    public function removeResponse(int $i): void
    {
        if (count($this->responses) > 1) {
            unset($this->responses[$i]);
            $this->responses = array_values($this->responses);
        }
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->reset(['editingId', 'triggers', 'responses']);
        $this->resetValidation();
    }

    public function save(RuleWriter $writer): void
    {
        $this->validate();

        // Fatia 4: guardas de negocio + persistencia vivem no RuleWriter (caminho
        // OFICIAL, compartilhado com a promocao "virar regra" do /revisao).
        $res = $writer->save($this->accountId(), [
            'triggers' => $this->triggers,
            'responses' => $this->responses,
            'enabled' => $this->enabled,
            'cooldown_mode' => $this->cooldownMode,
            'cooldown_minutes' => $this->cooldownMinutes,
            'scope' => $this->scope,
            'contact_ids' => $this->scopeContactIds,
            'tag_ids' => $this->scopeTagIds,
            'ai_match_enabled' => $this->aiMatchEnabled,
            'ai_examples' => $this->aiExamples,
        ], $this->editingId);

        if ($res['errors'] !== []) {
            foreach ($res['errors'] as $campo => $msg) {
                $this->addError($campo, $msg);
            }

            return;
        }

        $this->closeForm();
        foreach ($res['warnings'] ?? [] as $aviso) {
            $this->dispatch('toast', message: 'Aviso: ' . $aviso, type: 'error');
        }
        $this->dispatch('toast', message: 'Regra salva.');
    }

    public function toggle(int $id): void
    {
        $rule = $this->query()->find($id);
        if ($rule) {
            $rule->update(['enabled' => ! $rule->enabled]);
            $this->dispatch('toast', message: $rule->enabled ? 'Regra ativada.' : 'Regra desativada.');
        }
    }

    public function confirmDelete(int $id): void
    {
        $this->confirmingDeleteId = $id;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    public function deleteConfirmed(): void
    {
        if ($this->confirmingDeleteId) {
            // Exclusao escopada por account (WHERE id + account_id). As filhas caem por
            // cascade (FK cascadeOnDelete).
            $this->query()->where('id', $this->confirmingDeleteId)->delete();
            $this->dispatch('toast', message: 'Regra excluida.');
        }
        $this->confirmingDeleteId = null;
    }

    // ---- S4: testador (dry-run) --------------------------------------------

    public function openTester(): void
    {
        $this->testResult = null;
        $this->testReveal = false;
        $this->showTester = true;
    }

    public function closeTester(): void
    {
        $this->showTester = false;
        $this->testReveal = false;
        $this->testResult = null;
    }

    public function runTest(RuleTester $tester): void
    {
        // Re-testar sempre re-mascara a senha (revelar e deliberado, via revealTest).
        $this->testReveal = false;
        $this->testResult = $tester->test($this->accountId(), $this->channelId(), $this->testSample, $this->testContactId ?: null, false);
    }

    /** S6 — revelar a senha no resultado do testador (deliberado, transiente). */
    public function revealTest(RuleTester $tester): void
    {
        $this->testReveal = true;
        $this->testResult = $tester->test($this->accountId(), $this->channelId(), $this->testSample, $this->testContactId ?: null, true);
    }

    private function channelId(): ?int
    {
        return Channel::query()->where('account_id', $this->accountId())->oldest('id')->value('id');
    }


    private function query()
    {
        return AutoReplyRule::query()->where('account_id', $this->accountId());
    }

    private function accountId(): int
    {
        // MT-0: conta do CONTEXTO (fase 1 = conta unica, fallback centralizado).
        return app(\App\Tenancy\AccountContext::class)->id();
    }

    public function render()
    {
        // Fatia 0: ordem da lista = criacao (id); a precedencia agora e por especificidade.
        $rules = $this->query()->with(['triggers', 'responses', 'contacts', 'tags'])->orderBy('id')->get();
        $deleting = $this->confirmingDeleteId ? $rules->firstWhere('id', $this->confirmingDeleteId) : null;

        // Fatia 0: detector de sobreposicao (avisa regras que casariam a mesma mensagem).
        $detector = app(\App\Whatsapp\AutoReply\RuleConflictDetector::class);
        $conflicts = $detector->conflicts($this->accountId());
        // C.2: regra sombreada por fluxo de entrada (o fluxo vence).
        $flowOverlap = $detector->flowRuleOverlaps($this->accountId())['rules'];

        // Contatos pro seletor (escopo S3 + testador S4).
        $contacts = ($this->showTester || $this->showForm)
            ? Contact::query()->where('account_id', $this->accountId())
                ->orderByRaw('COALESCE(push_name, remote_jid)')->limit(500)
                ->get(['id', 'push_name', 'remote_jid'])
            : collect();

        // Lista do escopo (S3): filtrada pela busca (nome/numero), server-side.
        $busca = mb_strtolower(trim($this->scopeSearch), 'UTF-8');
        $scopeContacts = ($this->showForm && $this->scope === 'contatos' && $busca !== '')
            ? $contacts->filter(fn ($c) => str_contains(mb_strtolower(($c->push_name ?? '') . ' ' . $c->remote_jid, 'UTF-8'), $busca))->values()
            : $contacts;

        // S3 — nomes das senhas pro picker (NUNCA valores).
        $secretNames = $this->showForm ? app(SecretVault::class)->names($this->accountId()) : [];

        return view('livewire.regras', [
            'allTags' => ($this->showForm) ? \App\Models\Tag::query()->orderBy('name')->get() : collect(),
            'rules' => $rules,
            'deleting' => $deleting,
            'contacts' => $contacts,
            'scopeContacts' => $scopeContacts,
            'secretNames' => $secretNames,
            'conflicts' => $conflicts,
            'flowOverlap' => $flowOverlap,
        ]);
    }
}
