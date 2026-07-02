<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Envio MANUAL (R1) efetivado — log 'sent'.
 * Evento de dominio do pipeline (Kanban K-1+): payload minimo, consumido por
 * listeners EM FILA (observadores puros — nunca alteram o pipeline reativo).
 */
class ManualMessageSent
{
    use Dispatchable;

    public function __construct(
        public readonly int $accountId,
        public readonly int $autoReplyLogId,
        public readonly string $remoteJid,
    ) {
    }
}
