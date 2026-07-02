<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\PendingApproval;
use App\Tenancy\AccountContext;
use Illuminate\Console\Command;

/**
 * Camada 3 Fatia 3 — expiracao leve das pendencias de aprovacao. Pendencia com mais
 * de N dias (config ai.approval_expire_days, default 7) vira 'expired' — NADA e
 * enviado. Agendado diariamente (routes/console.php); o /revisao tambem expira
 * lazy no mount, entao o comando e cinto-e-suspensorio (vale quando o scheduler
 * estiver no ar; sem ele, a tela cobre).
 *
 * MT-0: itera TODAS as contas com contexto explicito por conta (--account restringe).
 */
class ExpireApprovals extends Command
{
    protected $signature = 'ai:expire-approvals {--account= : Expirar so desta conta}';

    protected $description = 'Expira pendencias de aprovacao da IA mais velhas que o limite (nada e enviado)';

    public function handle(AccountContext $context): int
    {
        $contas = $this->option('account')
            ? [(int) $this->option('account')]
            : Account::query()->pluck('id')->all();

        $total = 0;
        foreach ($contas as $accountId) {
            $total += $context->runAs($accountId, fn () => PendingApproval::expireStale($accountId));
        }

        $this->info("{$total} pendencia(s) expirada(s) em " . count($contas) . ' conta(s).');

        return self::SUCCESS;
    }
}
