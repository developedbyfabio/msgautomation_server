<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Mensagem INDIVIDUAL recebida e persistida (fromMe e grupos ficam fora).
 * Evento de dominio do pipeline (Kanban K-1+): payload minimo, consumido por
 * listeners EM FILA (observadores puros — nunca alteram o pipeline reativo).
 */
class IncomingMessageStored
{
    use Dispatchable;

    public function __construct(
        public readonly int $accountId,
        public readonly int $incomingMessageId,
        public readonly int $contactId,
        public readonly string $remoteJid,
    ) {
    }
}
