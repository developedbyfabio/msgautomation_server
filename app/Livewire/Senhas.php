<?php

namespace App\Livewire;

use App\Models\Account;
use App\Models\Secret;
use App\Whatsapp\Secrets\SecretVault;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * S2 — cofre de senhas (CRUD). Valor mascarado por padrao; revelar e ACAO DELIBERADA
 * que exige re-digitar a senha do login. Revela uma por vez; nunca a lista toda.
 * O valor decifrado NUNCA e logado. Editar nao carrega o valor no form (so metadados);
 * deixar o valor em branco mantem o atual.
 */
#[Layout('components.layouts.app')]
class Senhas extends Component
{
    public string $search = '';

    public bool $showForm = false;
    public ?int $editingId = null;
    public string $nome = '';
    public string $valor = '';
    public string $categoria = '';
    public string $notes = '';

    public ?int $confirmingDeleteId = null;

    // Revelar (deliberado, 1 por vez).
    public ?int $revealingId = null;   // pedindo senha de login
    public string $revealPassword = '';
    public ?int $revealedId = null;    // atualmente revelado
    public ?string $revealedValue = null;

    protected function rules(): array
    {
        return [
            'nome' => 'required|string|max:100|regex:/^[\w.\- ]+$/u',
            'valor' => ($this->editingId ? 'nullable' : 'required') . '|string|max:1000',
            'categoria' => 'nullable|string|max:60',
            'notes' => 'nullable|string|max:500',
        ];
    }

    public function novo(): void
    {
        $this->reset(['editingId', 'nome', 'valor', 'categoria', 'notes']);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $s = Secret::query()->where('account_id', $this->accountId())->findOrFail($id);
        $this->editingId = $s->id;
        $this->nome = (string) $s->nome;
        $this->valor = ''; // NAO carrega o valor (evita expor no payload). Em branco = manter.
        $this->categoria = (string) $s->categoria;
        $this->notes = (string) $s->notes;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->reset(['editingId', 'nome', 'valor', 'categoria', 'notes']);
        $this->resetValidation();
    }

    public function save(SecretVault $vault): void
    {
        $this->validate();
        $nome = trim($this->nome);
        $cat = trim($this->categoria) !== '' ? trim($this->categoria) : null;
        $notes = trim($this->notes) !== '' ? trim($this->notes) : null;

        if ($this->editingId) {
            $s = Secret::query()->where('account_id', $this->accountId())->findOrFail($this->editingId);
            $s->update(['nome' => $nome, 'categoria' => $cat, 'notes' => $notes]);
            if (trim($this->valor) !== '') {
                $vault->put($this->accountId(), $nome, $this->valor, $cat, $notes);
            }
        } else {
            $vault->put($this->accountId(), $nome, $this->valor, $cat, $notes);
        }

        // Limpa o plaintext do componente.
        $this->valor = '';
        $this->closeForm();
        $this->dispatch('toast', message: 'Senha salva.');
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
            Secret::query()->where('account_id', $this->accountId())->where('id', $this->confirmingDeleteId)->delete();
            $this->dispatch('toast', message: 'Senha excluida.');
        }
        $this->confirmingDeleteId = null;
        $this->hideReveal();
    }

    // ---- Revelar (deliberado) ----------------------------------------------

    public function askReveal(int $id): void
    {
        $this->hideReveal();
        $this->revealingId = $id;
        $this->revealPassword = '';
    }

    public function cancelReveal(): void
    {
        $this->revealingId = null;
        $this->revealPassword = '';
    }

    public function confirmReveal(SecretVault $vault): void
    {
        $user = Auth::user();
        if (! $user || ! Hash::check($this->revealPassword, (string) $user->password)) {
            $this->addError('revealPassword', 'Senha de login incorreta.');

            return;
        }

        $s = Secret::query()->where('account_id', $this->accountId())->find($this->revealingId);
        $this->revealPassword = '';
        if (! $s) {
            $this->cancelReveal();

            return;
        }

        // Decifra SO em memoria pra exibir; nao persiste, nao loga.
        $this->revealedId = $s->id;
        $this->revealedValue = $vault->reveal($this->accountId(), $s->nome);
        $this->revealingId = null;
    }

    public function hideReveal(): void
    {
        $this->revealedId = null;
        $this->revealedValue = null;
        $this->revealingId = null;
        $this->revealPassword = '';
    }

    private function accountId(): int
    {
        // MT-0: conta do CONTEXTO (fase 1 = conta unica, fallback centralizado).
        return app(\App\Tenancy\AccountContext::class)->id();
    }

    public function render()
    {
        $secrets = Secret::query()
            ->where('account_id', $this->accountId())
            ->when($this->search !== '', fn ($q) => $q->where('nome', 'like', '%' . $this->search . '%'))
            ->orderBy('nome')
            ->get(['id', 'nome', 'categoria', 'notes']); // NUNCA traz value_encrypted

        $deleting = $this->confirmingDeleteId
            ? Secret::query()->where('account_id', $this->accountId())->find($this->confirmingDeleteId)
            : null;

        return view('livewire.senhas', ['secrets' => $secrets, 'deleting' => $deleting]);
    }
}
