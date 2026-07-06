<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fatia 22 — backfill DEFENSIVO dos papeis de conta: (1) vinculo sem papel
 * (null/vazio) vira 'operador' (default seguro); (2) conta SEM nenhum owner
 * promove o vinculo mais ANTIGO a owner (conta nunca fica orfa — fail-safe).
 * Idempotente: re-rodar nao muda nada. UPDATE escopado do campo role apenas;
 * ninguem perde acesso (so ganha papel coerente).
 */
class BackfillAccountRoles extends Command
{
    protected $signature = 'msg:backfill-roles';

    protected $description = 'Garante papel valido em todo vinculo e >= 1 owner por conta. Idempotente.';

    public function handle(): int
    {
        // 1) Vinculos sem papel -> operador (default seguro).
        $semPapel = DB::table('account_user')
            ->where(fn ($q) => $q->whereNull('role')->orWhere('role', ''))
            ->update(['role' => 'operador']);
        $this->line("  vinculos sem papel -> operador: {$semPapel}");

        // 2) Conta sem NENHUM owner -> promove o vinculo mais antigo.
        $promovidos = 0;
        foreach (DB::table('accounts')->orderBy('id')->pluck('id') as $accountId) {
            $temOwner = DB::table('account_user')->where('account_id', $accountId)->where('role', 'owner')->exists();
            if ($temOwner) {
                continue;
            }
            $maisAntigo = DB::table('account_user')->where('account_id', $accountId)->orderBy('id')->first();
            if ($maisAntigo === null) {
                $this->warn("  conta #{$accountId} sem nenhum usuario vinculado — nada a promover.");

                continue;
            }
            DB::table('account_user')->where('id', $maisAntigo->id)->update(['role' => 'owner']);
            $promovidos++;
            $this->line("  conta #{$accountId}: user #{$maisAntigo->user_id} promovido a owner (era o vinculo mais antigo).");
        }
        $this->line("  promovidos a owner: {$promovidos}");
        $this->info('Pronto. Re-rodar e seguro (idempotente).');

        return self::SUCCESS;
    }
}
