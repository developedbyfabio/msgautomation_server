<?php

namespace App\Livewire\Admin;

use App\Actions\CreateTenant;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Prompt 22/25 — administracao de tenants (super-admin). UNICO ponto cross-tenant,
 * gated por 'platform.admin' (rota). Le/cria/edita SO a ESTRUTURA (Account + User,
 * ambos transversais) — nunca dados escopados de tenant. NUNCA toca is_platform_admin
 * (fora do fillable; sem caminho de escalonamento pela UI).
 */
#[Layout('components.layouts.app')]
class Tenants extends Component
{
    // criar tenant (prompt 22)
    public bool $showCreate = false;
    public string $accountName = '';
    public string $ownerName = '';
    public string $ownerEmail = '';
    public string $ownerPassword = '';

    // editar tenant (prompt 25)
    public ?int $editingId = null;
    public string $editName = '';
    // add usuario
    public string $nuName = '';
    public string $nuEmail = '';
    public string $nuPassword = '';
    public bool $nuOwner = false;
    // editar email / resetar senha (por usuario)
    public ?int $rowUserId = null;
    public string $rowEmail = '';
    public string $rowPassword = '';

    // ---- criar (prompt 22) -----------------------------------------------------

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
            'accountName' => 'nome da conta', 'ownerName' => 'nome do owner',
            'ownerEmail' => 'email do owner', 'ownerPassword' => 'senha',
        ]);

        $creator->handle($dados['accountName'], $dados['ownerName'], $dados['ownerEmail'], $dados['ownerPassword']);

        $this->showCreate = false;
        $this->reset(['accountName', 'ownerName', 'ownerEmail', 'ownerPassword']);
        $this->dispatch('toast', message: 'Tenant criado.');
    }

    // ---- editar tenant (prompt 25) ---------------------------------------------

    public function openEdit(int $id): void
    {
        $account = Account::findOrFail($id);
        $this->editingId = $account->id;
        $this->editName = $account->name;
        $this->reset(['nuName', 'nuEmail', 'nuPassword', 'nuOwner', 'rowUserId', 'rowEmail', 'rowPassword']);
        $this->resetErrorBag();
    }

    public function closeEdit(): void
    {
        $this->editingId = null;
    }

    /** Renomeia a conta (SO o nome de exibicao). O slug/instancia Evolution e congelado
     *  em channels.instance no provisionamento — nao muda ao renomear. */
    public function salvarConta(): void
    {
        $account = $this->accountEmEdicao();
        $this->validate([
            'editName' => ['required', 'string', 'max:120', Rule::unique('accounts', 'name')->ignore($account->id)],
        ], [], ['editName' => 'nome da conta']);

        $account->update(['name' => trim($this->editName)]);
        $this->dispatch('toast', message: 'Conta renomeada.');
    }

    public function adicionarUsuario(): void
    {
        $account = $this->accountEmEdicao();
        $dados = $this->validate([
            'nuName' => 'required|string|max:120',
            'nuEmail' => 'required|email|max:190|unique:users,email',
            'nuPassword' => 'required|string|min:10',
        ], [], ['nuName' => 'nome', 'nuEmail' => 'email', 'nuPassword' => 'senha']);

        $user = User::create([
            'name' => trim($dados['nuName']),
            'email' => mb_strtolower(trim($dados['nuEmail'])),
            'password' => Hash::make($dados['nuPassword']), // cast 'hashed' nao re-hasheia
        ]);
        // is_platform_admin NAO e setado (default false) — sem escalonamento pela UI.
        $user->accounts()->syncWithoutDetaching([$account->id => ['role' => $this->nuOwner ? 'owner' : 'operador']]);

        $this->reset(['nuName', 'nuEmail', 'nuPassword', 'nuOwner']);
        $this->dispatch('toast', message: 'Usuario adicionado.');
    }

    public function editarEmail(int $userId): void
    {
        $account = $this->accountEmEdicao();
        $user = $this->usuarioDoTenant($account, $userId);

        $this->validate([
            'rowEmail' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
        ], [], ['rowEmail' => 'email']);

        $user->update(['email' => mb_strtolower(trim($this->rowEmail))]);
        $this->reset(['rowUserId', 'rowEmail']);
        $this->dispatch('toast', message: 'Email atualizado.');
    }

    public function resetarSenha(int $userId): void
    {
        $account = $this->accountEmEdicao();
        $user = $this->usuarioDoTenant($account, $userId);

        $this->validate(['rowPassword' => 'required|string|min:10'], [], ['rowPassword' => 'senha']);

        $user->update(['password' => Hash::make($this->rowPassword)]); // nunca logada; so hash
        $this->reset(['rowUserId', 'rowPassword']);
        $this->dispatch('toast', message: 'Senha redefinida.');
    }

    public function alternarOwner(int $userId): void
    {
        $account = $this->accountEmEdicao();
        $user = $this->usuarioDoTenant($account, $userId);
        $roleAtual = (string) $user->accounts()->where('accounts.id', $account->id)->first()?->pivot->role;

        // Rebaixar o ULTIMO owner deixaria o tenant orfao — bloqueia.
        if ($roleAtual === 'owner' && $this->ownersCount($account) <= 1) {
            $this->dispatch('toast', message: 'Nao pode rebaixar o unico owner do tenant.', type: 'error');

            return;
        }

        $novo = $roleAtual === 'owner' ? 'operador' : 'owner';
        $account->users()->updateExistingPivot($user->id, ['role' => $novo]);
        $this->dispatch('toast', message: $novo === 'owner' ? 'Agora e owner.' : 'Agora e operador.');
    }

    public function removerUsuario(int $userId): void
    {
        $account = $this->accountEmEdicao();
        $user = $this->usuarioDoTenant($account, $userId);
        $role = (string) $user->accounts()->where('accounts.id', $account->id)->first()?->pivot->role;

        // Nunca deixar o tenant sem owner.
        if ($role === 'owner' && $this->ownersCount($account) <= 1) {
            $this->dispatch('toast', message: 'Nao pode remover o unico owner do tenant.', type: 'error');

            return;
        }

        // Remove o VINCULO com este tenant (nao apaga o usuario globalmente — pode
        // pertencer a outros tenants). Nao destrutivo.
        $account->users()->detach($user->id);
        $this->dispatch('toast', message: 'Usuario removido do tenant.');
    }

    // ---- helpers ---------------------------------------------------------------

    private function accountEmEdicao(): Account
    {
        abort_if($this->editingId === null, 404);

        return Account::findOrFail($this->editingId);
    }

    /** Garante que o usuario pertence AO tenant em edicao (nao cross-tenant). */
    private function usuarioDoTenant(Account $account, int $userId): User
    {
        $user = $account->users()->where('users.id', $userId)->first();
        abort_if($user === null, 404, 'Usuario nao pertence a este tenant.');

        return $user;
    }

    private function ownersCount(Account $account): int
    {
        return $account->users()->wherePivot('role', 'owner')->count();
    }

    public function render()
    {
        $tenants = Account::query()->withCount('users')->orderByDesc('id')->get()
            ->map(fn (Account $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'slug' => 'conta-' . $a->id . '-' . (Str::slug($a->name) ?: 'conta'),
                'users_count' => $a->users_count,
                'created_at' => $a->created_at,
            ]);

        $editing = null;
        $editUsers = collect();
        $editSlug = null;
        if ($this->editingId !== null) {
            $editing = Account::find($this->editingId);
            if ($editing !== null) {
                // Slug REAL = instancia congelada do canal (imutavel); sem canal, derivado.
                $canal = \App\Models\Channel::withoutAccountScope()
                    ->where('account_id', $editing->id)->oldest('id')->first();
                $editSlug = $canal?->instance ?? ('conta-' . $editing->id . '-' . (Str::slug($editing->name) ?: 'conta') . ' (sem canal ainda)');
                $editUsers = $editing->users()->orderBy('account_user.id')
                    ->get(['users.id', 'users.name', 'users.email'])
                    ->map(fn (User $u) => [
                        'id' => $u->id, 'name' => $u->name, 'email' => $u->email,
                        'role' => (string) $u->pivot->role,
                    ]);
            }
        }

        return view('livewire.admin.tenants', [
            'tenants' => $tenants,
            'editing' => $editing,
            'editSlug' => $editSlug,
            'editUsers' => $editUsers,
        ]);
    }
}
