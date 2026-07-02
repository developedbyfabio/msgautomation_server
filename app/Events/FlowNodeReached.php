<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Sessao de fluxo chegou num no (start/advance). Sem regra default no K-1; disponivel pra K-2/tags.
 * Evento de dominio do pipeline (Kanban K-1+): payload minimo, consumido por
 * listeners EM FILA (observadores puros — nunca alteram o pipeline reativo).
 */
class FlowNodeReached
{
    use Dispatchable;

    public function __construct(
        public readonly int $accountId,
        public readonly int $flowSessionId,
        public readonly string $remoteJid,
        public readonly int $nodeId,
        public readonly string $status,
    ) {
    }
}
