<?php

namespace App\Console\Commands;

use App\Jobs\SendProactiveMessage;
use App\Models\AutoReplySetting;
use App\Models\CampaignTarget;
use App\Tenancy\AccountContext;
use Illuminate\Console\Command;

/**
 * Proativas P-3 — TICK (schedule a cada minuto). NAO envia: so ENFILEIRA, em
 * lote pequeno por conta (config proactive.tick_batch, default 5 — nunca raja).
 *
 * So contas com `proactive_enabled` LIGADO (hoje: nenhuma — o tick roda e nao
 * faz nada, barato). Targets pending vencidos (scheduled_at <= agora) de
 * campanhas approved|running. A idempotencia do envio e do claim do target no
 * job (tick duplo/reentrega nao duplica).
 */
class ProactiveTick extends Command
{
    protected $signature = 'proactive:tick';

    protected $description = 'Enfileira envios proativos vencidos (contas com o interruptor ligado)';

    public function handle(AccountContext $context): int
    {
        $batch = max(1, (int) config('proactive.tick_batch', 5));
        $contas = AutoReplySetting::withoutAccountScope()
            ->where('proactive_enabled', true)
            ->pluck('account_id');

        $total = 0;
        foreach ($contas as $accountId) {
            $total += $context->runAs((int) $accountId, function () use ($accountId, $batch) {
                $targets = CampaignTarget::query()
                    ->where('status', 'pending')
                    ->whereNotNull('scheduled_at')
                    ->where('scheduled_at', '<=', now())
                    ->whereHas('campaign', fn ($q) => $q
                        ->withoutGlobalScope(\App\Tenancy\AccountScope::class)
                        ->where('account_id', $accountId)
                        ->whereIn('status', ['approved', 'running']))
                    ->orderBy('scheduled_at')
                    ->limit($batch)
                    ->get(['id']);

                foreach ($targets as $t) {
                    SendProactiveMessage::dispatch((int) $t->id, (int) $accountId);
                }

                return $targets->count();
            });
        }

        $this->info("{$total} envio(s) proativo(s) enfileirado(s) em " . $contas->count() . ' conta(s) com o interruptor ligado.');

        return self::SUCCESS;
    }
}
