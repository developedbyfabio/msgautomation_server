<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Resposta AUTOMATICA enviada (regra/fluxo/IA) ou aprovada no /revisao — log 'sent'.
 * Evento de dominio do pipeline (Kanban K-1+): payload minimo, consumido por
 * listeners EM FILA (observadores puros — nunca alteram o pipeline reativo).
 */
class AutoReplySent
{
    use Dispatchable;

    public function __construct(
        public readonly int $accountId,
        public readonly int $autoReplyLogId,
        public readonly string $remoteJid,
        public readonly string $mode,
        // Fatia 11 — despedida de HANDOFF: o card ja foi movido pra 'aguardando'
        // pelo proprio handoff (deterministico, no motor); este envio NAO deve
        // disparar a regra resposta_enviada (regrediria o card pra em_atendimento).
        public readonly bool $handoff = false,
    ) {
    }
}
