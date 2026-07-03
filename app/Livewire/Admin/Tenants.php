<?php

namespace App\Livewire\Admin;

use App\Actions\CreateTenant;
use App\Models\Account;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Prompt 22 — administracao de tenants (super-admin da plataforma). UNICO ponto
 * cross-tenant, gated por 'platform.admin' (rota). So le/cria a ESTRUTURA
 * (Account + User owner, ambos transversais) — nunca dados escopados de tenant.
 */
#[Layout('components.layouts.app')]
class Tenants extends Component
{
    public bool $showCreate = false;
    public string $accountName = '';
    public string $ownerName = '';
    public string $ownerEmail = '';
    public string $ownerPassword = '';

    public function openCreate(): void
    {
        $this->reset(['accountName', 'ownerName', 'ownerEmail', 'ownerPassword']);
        $this->resetErrorBag();
        $this->showCreate = true;
    }

    public function cancelCreate(): void
    {
        $this->showCreate = false;
    }

    public function criar(CreateTenant $creator): void
    {
        $dados = $this->validate([
            'accountName' => 'required|string|max:120|unique:accounts,name',
            'ownerName' => 'required|string|max:120',
            'ownerEmail' => 'required|email|max:190|unique:users,email',
            'ownerPassword' => 'required|string|min:10',
        ], [], [
            'accountName' => 'nome da conta',
            'ownerName' => 'nome do owner',
            'ownerEmail' => 'email do owner',
            'ownerPassword' => 'senha',
        ]);

        $creator->handle($dados['accountName'], $dados['ownerName'], $dados['ownerEmail'], $dados['ownerPassword']);

        $this->showCreate = false;
        $this->reset(['accountName', 'ownerName', 'ownerEmail', 'ownerPassword']);
        $this->dispatch('toast', message: 'Tenant criado.');
    }

    public function render()
    {
        // Transversal (Account/User nao usam BelongsToAccount): lista TODOS os tenants.
        // Cross-tenant PROPOSITAL, so pro super-admin (rota gated). Sem dado escopado.
        $tenants = Account::query()
            ->withCount('users')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Account $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'slug' => 'conta-' . $a->id . '-' . (Str::slug($a->name) ?: 'conta'),
                'users_count' => $a->users_count,
                'created_at' => $a->created_at,
            ]);

        return view('livewire.admin.tenants', ['tenants' => $tenants]);
    }
}
