<?php

namespace App\Jobs;

use App\Billing\BillingState;
use App\Models\Account;
use App\Models\BillingWebhookEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Fatia 26 — processa UM evento de webhook do Asaas (fora do request: o
 * endpoint ja respondeu 200). Resolve a conta pelo atributo `subscription` da
 * cobranca (vinculo travado; fallback: customer id) e aplica a transicao da
 * maquina de estados. IDEMPOTENTE em dois niveis: o dedup do endpoint (event id
 * unique) + o processed_at aqui (retry do proprio job nao reaplica) + a
 * transicao em si (mesmo alvo = no-op). Evento sem conta (ex.: assinatura de
 * teste que nao e de nenhum tenant) = 'ignored', nada muda — e como conta
 * LEGACY fica imune: sem asaas_subscription_id, nunca e resolvida.
 */
class ProcessAsaasWebhookEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public int $eventoId)
    {
    }

    public function handle(BillingState $maquina): void
    {
        $evento = BillingWebhookEvent::query()->find($this->eventoId);
        if ($evento === null || $evento->processed_at !== null) {
            return; // ja processado (retry do job): no-op
        }

        $conta = null;
        if ($evento->subscription_id !== null) {
            $conta = Account::query()->where('asaas_subscription_id', $evento->subscription_id)->first();
        }
        if ($conta === null && $evento->customer_id !== null) {
            // Fallback: cobranca sem `subscription` (avulsa) — resolve pelo customer.
            $conta = Account::query()->where('asaas_customer_id', $evento->customer_id)->first();
        }

        if ($conta === null) {
            $evento->update(['status' => 'ignored', 'processed_at' => now()]);

            return;
        }

        $maquina->aplicarEvento($conta, $evento->event);

        $evento->update([
            'account_id' => $conta->id,
            'status' => 'processed',
            'processed_at' => now(),
        ]);
    }
}
