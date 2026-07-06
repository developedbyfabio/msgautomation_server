<?php

namespace App\Billing;

use App\Models\Account;
use App\Models\SystemEvent;

/**
 * Fatia 26 — MAQUINA DE ESTADOS da assinatura, por conta:
 *
 *   trial -> active -> overdue -> suspended -> canceled
 *
 * Dirigida pelos webhooks de COBRANCA do Asaas (o webhook e de payment; o
 * vinculo com a conta e o atributo `subscription` do payload) + o sweep diario
 * do corte de trial (billing:sweep). IDEMPOTENTE: mesmo evento/alvo duas vezes
 * = mesmo estado, sem efeito duplicado. Contas legacy (sem Asaas, sem trial)
 * sao imunes por construcao: nenhum caminho as alcanca.
 *
 * Regras registradas:
 *  - 'canceled' e terminal para EVENTO DE COBRANCA (um pagamento atrasado que
 *    entra depois do cancelamento nao "descancela"); so um NOVO checkout no
 *    painel rearma (canceled -> overdue, aguardando o pagamento confirmar).
 *  - PAYMENT_DELETED/RESTORED nao mudam estado (cobranca avulsa manipulada no
 *    Asaas nao decide assinatura) — registrados como 'ignored'.
 *  - Suspensao NUNCA apaga dado (regra dura da Fatia 20): so muda o status;
 *    pagamento confirmado volta pra 'active' e limpa os marcos.
 */
class BillingState
{
    public const SUSPENSOS = ['suspended', 'canceled'];

    /** Evento de cobranca do Asaas -> estado alvo (null = registrar e ignorar). */
    public const EVENTO_ESTADO = [
        'PAYMENT_CONFIRMED' => 'active',   // cartao aprovado (liquidacao depois)
        'PAYMENT_RECEIVED' => 'active',    // dinheiro recebido (Pix/boleto)
        'PAYMENT_OVERDUE' => 'overdue',    // cobranca venceu sem pagamento
        'PAYMENT_REFUNDED' => 'suspended', // estorno desfaz o acesso pago (reversivel)
        'PAYMENT_CHARGEBACK_REQUESTED' => 'suspended',
        'PAYMENT_DELETED' => null,
        'PAYMENT_RESTORED' => null,
    ];

    /** @return bool true se o estado MUDOU (false = no-op idempotente/ignorado). */
    public function aplicarEvento(Account $conta, string $evento): bool
    {
        $alvo = self::EVENTO_ESTADO[$evento] ?? null;
        if ($alvo === null) {
            return false;
        }

        // canceled: terminal pra cobranca (so o painel rearma com novo checkout).
        if ($conta->subscription_status === 'canceled') {
            return false;
        }

        return $this->transicionar($conta, $alvo, 'webhook:' . $evento);
    }

    /** Transicao direta (sweep do trial, cancelamento/novo checkout no painel). */
    public function transicionar(Account $conta, string $alvo, string $causa): bool
    {
        if ($conta->subscription_status === $alvo) {
            return false; // idempotente: reaplicar nao tem efeito colateral
        }

        $anterior = $conta->subscription_status;
        $conta->forceFill([
            'subscription_status' => $alvo,
            'overdue_since' => $alvo === 'overdue' ? ($conta->overdue_since ?? now()) : null,
            'suspended_at' => in_array($alvo, self::SUSPENSOS, true) ? ($conta->suspended_at ?? now()) : null,
        ])->save();

        // Auditoria best-effort (tela /logs do tenant) — nunca derruba a transicao.
        try {
            SystemEvent::withoutAccountScope()->create([
                'account_id' => $conta->id,
                'type' => 'billing',
                'level' => in_array($alvo, self::SUSPENSOS, true) ? 'warning' : 'info',
                'title' => "Assinatura: {$anterior} -> {$alvo} ({$causa})",
                'occurred_at' => now(),
            ]);
        } catch (\Throwable) {
            // best-effort
        }

        return true;
    }
}
