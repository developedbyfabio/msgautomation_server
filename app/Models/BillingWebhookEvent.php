<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fatia 26 — evento de webhook do Asaas recebido. O unique de event_id e o
 * DEDUP (entrega "at least once": reentrega do mesmo evento vira no-op no
 * endpoint). SEM escopo de conta de proposito: e infraestrutura de billing
 * (o account_id e resolvido depois, no job, pelo subscription id) e NUNCA
 * aparece em tela de tenant.
 */
class BillingWebhookEvent extends Model
{
    protected $fillable = [
        'event_id', 'event', 'payment_id', 'subscription_id', 'customer_id',
        'account_id', 'status', 'payload', 'processed_at',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'datetime'];
    }
}
