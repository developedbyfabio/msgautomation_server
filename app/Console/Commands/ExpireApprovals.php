<?php

namespace App\Console\Commands;

use App\Models\PendingApproval;
use Illuminate\Console\Command;

/**
 * Camada 3 Fatia 3 — expiracao leve das pendencias de aprovacao. Pendencia com mais
 * de N dias (config ai.approval_expire_days, default 7) vira 'expired' — NADA e
 * enviado. Agendado diariamente (routes/console.php); o /revisao tambem expira
 * lazy no mount, entao o comando e cinto-e-suspensorio (vale quando o scheduler
 * estiver no ar; sem ele, a tela cobre).
 */
class ExpireApprovals extends Command
{
    protected $signature = 'ai:expire-approvals';

    protected $description = 'Expira pendencias de aprovacao da IA mais velhas que o limite (nada e enviado)';

    public function handle(): int
    {
        $n = PendingApproval::expireStale();

        $this->info("{$n} pendencia(s) expirada(s).");

        return self::SUCCESS;
    }
}
