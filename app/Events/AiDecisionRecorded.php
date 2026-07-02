<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Decisao da IA registrada (respondeu/escalou/silenciou). Sem regra default no K-1; disponivel pra K-2/tags.
 * Evento de dominio do pipeline (Kanban K-1+): payload minimo, consumido por
 * listeners EM FILA (observadores puros — nunca alteram o pipeline reativo).
 */
class AiDecisionRecorded
{
    use Dispatchable;

    public function __construct(
        public readonly int $accountId,
        public readonly int $aiDecisionId,
        public readonly string $remoteJid,
        public readonly string $acao,
    ) {
    }
}
