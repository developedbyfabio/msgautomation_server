<?php

namespace App\Livewire;

use App\Models\Account;
use App\Models\AutoReplyRule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Regras extends Component
{
    public bool $showForm = false;
    public ?int $editingId = null;
    public ?int $confirmingDeleteId = null;

    public string $match_type = 'contains';
    public string $match_value = '';
    public string $response_text = '';
    public bool $enabled = true;

    protected function rules(): array
    {
        return [
            'match_type' => 'required|in:exact,contains,starts_with',
            'match_value' => 'required|string|max:255',
            'response_text' => 'required|string|max:4000',
            'enabled' => 'boolean',
        ];
    }

    public function novo(): void
    {
        $this->reset(['editingId', 'match_value', 'response_text']);
        $this->match_type = 'contains';
        $this->enabled = true;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $rule = $this->query()->findOrFail($id);
        $this->editingId = $rule->id;
        $this->match_type = $rule->match_type;
        $this->match_value = $rule->match_value;
        $this->response_text = $rule->response_text;
        $this->enabled = (bool) $rule->enabled;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->reset(['editingId', 'match_value', 'response_text']);
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->validate();

        if ($this->editingId) {
            $this->query()->where('id', $this->editingId)->update([
                'match_type' => $this->match_type,
                'match_value' => $this->match_value,
                'response_text' => $this->response_text,
                'enabled' => $this->enabled,
            ]);
        } else {
            $next = (int) ($this->query()->max('priority') ?? -1) + 1;
            AutoReplyRule::create([
                'account_id' => $this->accountId(),
                'match_type' => $this->match_type,
                'match_value' => $this->match_value,
                'response_text' => $this->response_text,
                'enabled' => $this->enabled,
                'priority' => $next,
            ]);
        }

        $this->closeForm();
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
            // Exclusao escopada por account (WHERE id + account_id) — acao de CRUD do usuario.
            $this->query()->where('id', $this->confirmingDeleteId)->delete();
            $this->dispatch('toast', message: 'Regra excluida.');
        }
        $this->confirmingDeleteId = null;
    }

    public function move(int $id, string $dir): void
    {
        $rules = $this->query()->orderBy('priority')->orderBy('id')->get();
        $idx = $rules->search(fn ($r) => $r->id === $id);
        if ($idx === false) {
            return;
        }
        $swap = $dir === 'up' ? $idx - 1 : $idx + 1;
        if ($swap < 0 || $swap >= $rules->count()) {
            return;
        }

        foreach ($rules as $i => $r) {
            if ((int) $r->priority !== $i) {
                $r->update(['priority' => $i]);
            }
        }
        $rules[$idx]->update(['priority' => $swap]);
        $rules[$swap]->update(['priority' => $idx]);
    }

    private function query()
    {
        return AutoReplyRule::query()->where('account_id', $this->accountId());
    }

    private function accountId(): int
    {
        $account = Account::query()->oldest('id')->first()
            ?? Account::create(['name' => config('app.name', 'msgautomation')]);

        return $account->id;
    }

    public function render()
    {
        $rules = $this->query()->orderBy('priority')->orderBy('id')->get();
        $deleting = $this->confirmingDeleteId ? $rules->firstWhere('id', $this->confirmingDeleteId) : null;

        return view('livewire.regras', ['rules' => $rules, 'deleting' => $deleting]);
    }
}
