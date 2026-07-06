<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessAsaasWebhookEvent;
use App\Models\BillingWebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fatia 26 — webhook de COBRANCA do Asaas. Tres invariantes inegociaveis:
 *
 *  1. AUTENTICIDADE: o Asaas manda o authToken configurado no header
 *     `asaas-access-token`; sem/errado -> 401 SEM processar (senao qualquer um
 *     forja um POST e ativa a propria conta de graca). hash_equals (timing-safe).
 *  2. IDEMPOTENCIA: entrega "at least once" (retry). Dedup pelo `id` do evento
 *     (unique no banco): reentrega -> 200 no-op, sem reprocessar.
 *  3. RAPIDO + ASSINCRONO: responde 200 imediatamente e ENFILEIRA o job (fila
 *     do Asaas interrompe apos 15 falhas consecutivas; nunca processar inline).
 *
 * A transicao de estado acontece no JOB (ProcessAsaasWebhookEvent), que resolve
 * a conta pelo atributo `subscription` do payment — o webhook do Asaas e de
 * cobranca, nao de assinatura (doc oficial).
 */
class AsaasWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // 1. autenticidade (timing-safe; token vazio no .env NUNCA autoriza).
        $esperado = (string) config('billing.asaas.webhook_token');
        $recebido = (string) $request->header('asaas-access-token', '');
        if ($esperado === '' || ! hash_equals($esperado, $recebido)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $eventId = (string) $request->input('id', '');
        $evento = (string) $request->input('event', '');
        $payment = (array) $request->input('payment', []);
        if ($eventId === '' || $evento === '') {
            // Payload sem identidade: 200 (nao ha o que reprocessar num retry).
            return response()->json(['received' => true]);
        }

        // 2. dedup por event id — o unique decide a corrida (dois retries
        //    simultaneos: um insere, o outro cai aqui e vira no-op).
        try {
            $registro = BillingWebhookEvent::create([
                'event_id' => $eventId,
                'event' => $evento,
                'payment_id' => $payment['id'] ?? null,
                'subscription_id' => $payment['subscription'] ?? null,
                'customer_id' => is_string($payment['customer'] ?? null) ? $payment['customer'] : ($payment['customer']['id'] ?? null),
                'payload' => ['event' => $evento, 'payment' => $payment], // sem dado de cartao (o Asaas nao manda)
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['received' => true]); // ja recebido: no-op
        }

        // 3. 200 imediato; o trabalho fica no job.
        ProcessAsaasWebhookEvent::dispatch($registro->id);

        return response()->json(['received' => true]);
    }
}
