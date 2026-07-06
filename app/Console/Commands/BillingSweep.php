<?php

namespace App\Console\Commands;

use App\Billing\BillingState;
use App\Models\Account;
use Illuminate\Console\Command;

/**
 * Fatia 26 — o CORTE que a Fatia 25 deixou pendente (agendado diario; nao
 * depende do webhook pro caso "trial venceu sem nunca ter pago"). Politica:
 *
 *  1. trial com trial_ends_at vencido -> 'overdue' (grava overdue_since);
 *  2. overdue ha >= billing.overdue_grace_days (default 5) -> 'suspended'.
 *
 * IMUNIDADE DO LEGACY por construcao: o passo 1 so alcanca status='trial' com
 * trial_ends_at preenchido (contas 1/2 e as criadas pelo admin sao 'active'
 * com trial null); o passo 2 so alcanca quem JA esta 'overdue'. Idempotente:
 * rodar duas vezes nao re-transiciona (a maquina e no-op no mesmo estado).
 * NADA e apagado: suspensao e reversivel (pagamento -> webhook -> active).
 */
class BillingSweep extends Command
{
    protected $signature = 'billing:sweep';

    protected $description = 'Corte de trial/inadimplencia: trial vencido -> overdue; overdue alem da carencia -> suspended';

    public function handle(BillingState $maquina): int
    {
        $carencia = max(1, (int) config('billing.overdue_grace_days', 5));

        $vencidos = Account::query()
            ->where('subscription_status', 'trial')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', now())
            ->get();
        foreach ($vencidos as $conta) {
            $maquina->transicionar($conta, 'overdue', 'sweep:trial_vencido');
        }

        $estourados = Account::query()
            ->where('subscription_status', 'overdue')
            ->whereNotNull('overdue_since')
            ->where('overdue_since', '<=', now()->subDays($carencia))
            ->get();
        foreach ($estourados as $conta) {
            $maquina->transicionar($conta, 'suspended', 'sweep:carencia_estourada');
        }

        $this->info(sprintf('billing:sweep — %d trial(s) -> overdue; %d overdue -> suspended.', $vencidos->count(), $estourados->count()));

        return self::SUCCESS;
    }
}
