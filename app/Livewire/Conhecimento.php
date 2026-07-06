<?php

namespace App\Livewire;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Knowledge;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Camada 3 Fatia 2 — base de conhecimento (CRUD). Entradas que a IA pode usar pra
 * responder contatos em modo `conhecimento` (opt-in por contato em /contatos).
 *
 * Sensibilidade: low/medium vao ao modelo; `high` NUNCA vai nem e respondido direto
 * (escala pra revisao humana). Contatos permitidos: vazio = qualquer contato com IA
 * ligada em modo conhecimento; preenchido = so os marcados. Placeholders no conteudo
 * ({senha:nome}, {nome}, ...) sao resolvidos LOCALMENTE no envio, nunca vao expandidos
 * ao modelo — e resposta com {senha:} nunca e auto-enviada pela IA.
 */
#[Layout('components.layouts.app')]
class Conhecimento extends Component
{
    public string $search = '';

    public bool $showForm = false;
    public ?int $editingId = null;
    public string $title = '';
    public string $content = '';
    public string $sensitivity = 'medium';
    public bool $active = true;
    /** @var array<int,int> */
    public array $contactIds = [];
    public string $contactSearch = '';

    public ?int $confirmingDeleteId = null;

    protected function rules(): array
    {
        return [
            'title' => 'required|string|max:150',
            'content' => 'required|string|max:8000',
            'sensitivity' => 'required|in:low,medium,high',
            'active' => 'boolean',
            'contactIds' => 'array',
            'contactIds.*' => 'integer',
        ];
    }

    protected function messages(): array
    {
        return [
            'title.required' => 'Informe o titulo.',
            'content.required' => 'Informe o conteudo.',
        ];
    }

    /**
     * Fatia 14 — cria uma entrada REAL a partir de um template do catalogo (via
     * KnowledgeWriter oficial; titulo sufixado em colisao) e abre no form pro
     * usuario preencher os [placeholders]. Mesmo padrao da Fatia 7.
     */
    public function usarTemplate(string $key): void
    {
        // Fatia 23 — operador VE, nao escreve: gate server-side (acao forjavel).
        \App\Auth\AreaAccess::authorizeEditAction('conhecimento');

        try {
            $k = app(\App\Ai\InstantiateKnowledgeTemplate::class)->handle($key, $this->accountId());
        } catch (\InvalidArgumentException) {
            $this->dispatch('toast', message: 'Modelo de conhecimento desconhecido.', type: 'error');

            return;
        }

        $this->edit($k->id);
        $this->dispatch('toast', message: 'Entrada criada a partir do modelo — preencha os textos entre [colchetes].');
    }

    public function novo(): void
    {
        $this->reset(['editingId', 'title', 'content', 'contactIds', 'contactSearch']);
        $this->sensitivity = 'medium';
        $this->active = true;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $k = $this->query()->with('contacts')->findOrFail($id);
        $this->editingId = $k->id;
        $this->title = (string) $k->title;
        $this->content = (string) $k->content;
        $this->sensitivity = in_array($k->sensitivity, Knowledge::SENSITIVITIES, true) ? $k->sensitivity : 'medium';
        $this->active = (bool) $k->active;
        $this->contactIds = $k->contacts->pluck('id')->all();
        $this->contactSearch = '';
        $this->resetValidation();
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->reset(['editingId', 'title', 'content', 'contactIds', 'contactSearch']);
        $this->resetValidation();
    }

    public function save(\App\Ai\KnowledgeWriter $writer): void
    {
        // Fatia 23 — operador VE, nao escreve: gate server-side (acao forjavel).
        \App\Auth\AreaAccess::authorizeEditAction('conhecimento');

        $this->validate();

        // Fatia 4: guardas + persistencia no KnowledgeWriter (caminho OFICIAL,
        // compartilhado com a promocao "virar entrada" do /revisao). Inclui a
        // guarda: conteudo com {senha:...} exige contatos restritos.
        $res = $writer->save($this->accountId(), [
            'title' => $this->title,
            'content' => $this->content,
            'sensitivity' => $this->sensitivity,
            'active' => $this->active,
            'contact_ids' => $this->contactIds,
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
        $this->dispatch('toast', message: 'Entrada salva.');
    }

    public function toggle(int $id): void
    {
        // Fatia 23 — operador VE, nao escreve: gate server-side (acao forjavel).
        \App\Auth\AreaAccess::authorizeEditAction('conhecimento');

        $k = $this->query()->find($id);
        if ($k) {
            $k->update(['active' => ! $k->active]);
            $this->dispatch('toast', message: $k->active ? 'Entrada ativada.' : 'Entrada desativada.');
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
        // Fatia 23 — operador VE, nao escreve: gate server-side (acao forjavel).
        \App\Auth\AreaAccess::authorizeEditAction('conhecimento');

        if ($this->confirmingDeleteId) {
            // Exclusao escopada por account; o pivo cai por cascade. O historico em
            // ai_decisions (knowledge_ids, sem FK dura) permanece.
            $this->query()->where('id', $this->confirmingDeleteId)->delete();
            $this->dispatch('toast', message: 'Entrada excluida.');
        }
        $this->confirmingDeleteId = null;
    }

    private function query()
    {
        return Knowledge::query()->where('account_id', $this->accountId());
    }

    private function accountId(): int
    {
        // MT-0: conta do CONTEXTO (fase 1 = conta unica, fallback centralizado).
        return app(\App\Tenancy\AccountContext::class)->id();
    }

    public function render()
    {
        $entries = $this->query()
            ->with('contacts:id')
            ->when($this->search !== '', function ($q) {
                $q->where(function ($w) {
                    $w->where('title', 'like', '%' . $this->search . '%')
                        ->orWhere('content', 'like', '%' . $this->search . '%');
                });
            })
            ->orderBy('id')
            ->get();

        $deleting = $this->confirmingDeleteId
            ? $this->query()->find($this->confirmingDeleteId)
            : null;

        // Contatos pro seletor de permissao (mesmo padrao do escopo das regras).
        $contacts = $this->showForm
            ? Contact::query()->where('account_id', $this->accountId())
                ->orderByRaw('COALESCE(push_name, remote_jid)')->limit(500)
                ->get(['id', 'push_name', 'remote_jid'])
            : collect();

        $busca = mb_strtolower(trim($this->contactSearch), 'UTF-8');
        $filteredContacts = ($this->showForm && $busca !== '')
            ? $contacts->filter(fn ($c) => str_contains(mb_strtolower(($c->push_name ?? '') . ' ' . $c->remote_jid, 'UTF-8'), $busca))->values()
            : $contacts;

        return view('livewire.conhecimento', [
            // Fatia 23 — view-only do operador (cosmetico; gates = barreira real).
            'podeEditar' => \App\Auth\AreaAccess::canEditArea('conhecimento'),
            // Fatia 14 — catalogo de templates (padrao da Fatia 7).
            'templates' => $this->showForm ? [] : app(\App\Ai\KnowledgeTemplateCatalog::class)->summaries(),
            'entries' => $entries,
            'deleting' => $deleting,
            'contacts' => $filteredContacts,
        ]);
    }
}
