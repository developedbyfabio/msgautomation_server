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

    public function save(): void
    {
        $this->validate();

        // Contatos permitidos, validados como do mesmo account (vazio = todos com IA).
        $contactIds = Contact::query()->where('account_id', $this->accountId())
            ->whereIn('id', $this->contactIds)->pluck('id')->all();

        $dados = [
            'title' => trim($this->title),
            'content' => trim($this->content),
            'sensitivity' => $this->sensitivity,
            'active' => $this->active,
        ];

        if ($this->editingId) {
            $k = $this->query()->findOrFail($this->editingId);
            $k->update($dados);
        } else {
            $k = Knowledge::create(array_merge($dados, ['account_id' => $this->accountId()]));
        }

        $k->contacts()->sync($contactIds);

        $this->closeForm();
        $this->dispatch('toast', message: 'Entrada salva.');
    }

    public function toggle(int $id): void
    {
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
        return (int) (Account::query()->oldest('id')->value('id')
            ?? Account::create(['name' => config('app.name', 'msgautomation')])->id);
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
            'entries' => $entries,
            'deleting' => $deleting,
            'contacts' => $filteredContacts,
        ]);
    }
}
