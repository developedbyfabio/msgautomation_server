<?php

namespace App\Listeners;

use App\Events\AiDecisionRecorded;
use App\Events\AutoReplySent;
use App\Events\FlowNodeReached;
use App\Events\IncomingMessageStored;
use App\Events\ManualMessageSent;
use App\Kanban\BoardEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Kanban K-1 — UNICO listener dos eventos de dominio (registrado no
 * AppServiceProvider). EM FILA (ShouldQueue): nao adiciona latencia ao webhook.
 *
 * REGRA DE OURO: falha aqui NUNCA derruba o processamento da mensagem — o try/
 * catch engole QUALQUER erro (mesmo com fila sync) e loga. Se o Kanban inteiro
 * sumir, o robo se comporta identico.
 */
class UpdateKanbanFromEvent implements ShouldQueue
{
    public function __construct(private BoardEngine $engine)
    {
    }

    public function handle(object $event): void
    {
        try {
            match (true) {
                $event instanceof IncomingMessageStored => $this->engine->apply(
                    'mensagem_recebida', $event->accountId, $event->remoteJid, $event->incomingMessageId, 'in'),
                // Fatia 11 — despedida de HANDOFF nao e "resposta que reabre
                // atendimento": o handoff ja moveu o card pra 'aguardando' no motor
                // (deterministico, antes do envio); aplicar resposta_enviada aqui
                // regrediria o card pra em_atendimento (corrida). Suprimido.
                $event instanceof AutoReplySent => $event->handoff ? null : $this->engine->apply(
                    'resposta_enviada', $event->accountId, $event->remoteJid, $event->autoReplyLogId, 'out'),
                $event instanceof ManualMessageSent => $this->engine->apply(
                    'envio_manual', $event->accountId, $event->remoteJid, $event->autoReplyLogId, 'out'),
                $event instanceof FlowNodeReached => $this->engine->apply(
                    'fluxo_no', $event->accountId, $event->remoteJid, $event->flowSessionId, null),
                $event instanceof AiDecisionRecorded => $this->engine->apply(
                    'ia_decisao', $event->accountId, $event->remoteJid, $event->aiDecisionId, null,
                    ['intent' => $event->intent, 'acao' => $event->acao]),
                default => null,
            };
        } catch (\Throwable $e) {
            // Observador puro: erro do Kanban e ISOLADO (logado, nunca propaga).
            Log::error('Kanban: falha isolada no listener (pipeline segue normal).', [
                'evento' => $event::class,
                'erro' => $e->getMessage(),
            ]);
        }
    }
}
