<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Prompt 22 — cria um TENANT novo: Account + usuario OWNER vinculado. Reusa a
 * mesma disciplina do `user:create` (hash de senha, vinculo role=owner). A tela
 * de super-admin consome; nada aqui toca dados escopados de tenant.
 *
 * A Account nova ja nasce com Kanban + variaveis via Account::booted() (id
 * explicito nos provisioners — nao depende de AccountContext).
 */
class CreateTenant
{
    /**
     * @return array{account: Account, owner: User}
     */
    public function handle(string $accountName, string $ownerName, string $ownerEmail, string $ownerPassword): array
    {
        $accountName = trim($accountName);
        $ownerName = trim($ownerName);
        $ownerEmail = mb_strtolower(trim($ownerEmail));

        return DB::transaction(function () use ($accountName, $ownerName, $ownerEmail, $ownerPassword) {
            $account = Account::create(['name' => $accountName]); // booted() provisiona board + variaveis

            $owner = User::create([
                'name' => $ownerName,
                'email' => $ownerEmail,
                'password' => Hash::make($ownerPassword), // cast 'hashed' nao re-hasheia
            ]);
            // is_platform_admin NAO e setado aqui (default false) — owner de tenant, nao super-admin.

            $owner->accounts()->syncWithoutDetaching([$account->id => ['role' => 'owner']]);

            return ['account' => $account, 'owner' => $owner];
        });
    }
}
