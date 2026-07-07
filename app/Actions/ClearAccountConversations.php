<?php

namespace App\Actions;

use App\Models\AiDecision;
use App\Models\AutoReplyLog;
use App\Models\IncomingMessage;
use App\Models\PendingApproval;
use App\Models\SystemEvent;
use App\Models\UnmatchedMessage;
use App\Whatsapp\SystemConversation;
use Illuminate\Support\Facades\DB;

/**
 * "Limpar todas as conversas" — HARD DELETE das MENSAGENS de uma conta
 * (irreversível; o dono assumiu o risco). Decisão de escopo registrada:
 *
 *  - Apaga o CONTEÚDO da conversa: incoming_messages + auto_reply_logs (a
 *    lista do Atendimento é derivada EXCLUSIVAMENTE dessas duas tabelas por
 *    remote_jid — sem elas, as conversas somem) + os artefatos POR-MENSAGEM
 *    que ficariam órfãos (ai_decisions, pending_approvals, unmatched_messages).
 *  - PRESERVA `contacts` (e portanto Kanban/Campanhas/Regras/opt-in): apagar
 *    contatos CASCATEIA para cards, campaign_targets, contact_tag,
 *    rule_contacts, knowledge_contacts, proactive_consents — que são dados de
 *    OUTRAS features (proibido pelo escopo). "Limpar conversas" ≠ "apagar
 *    clientes". Adaptação consciente registrada no relatório.
 *  - PRESERVA a conversa de SISTEMA (Alertas de Infraestrutura): exclui o JID
 *    sintético do delete (o histórico de alertas fica).
 *  - ESCOPO por conta (nunca cruza contas) e AUDITORIA (SystemEvent, sem
 *    conteúdo das mensagens).
 */
class ClearAccountConversations
{
    /** Apaga as conversas da conta. Retorna quantas mensagens foram removidas. */
    public function handle(int $accountId, ?int $userId): int
    {
        return DB::transaction(function () use ($accountId, $userId) {
            // Artefatos por-mensagem (a conversa de sistema não gera nenhum).
            AiDecision::withoutAccountScope()->where('account_id', $accountId)->delete();
            PendingApproval::withoutAccountScope()->where('account_id', $accountId)->delete();
            UnmatchedMessage::withoutAccountScope()->where('account_id', $accountId)->delete();

            // Mensagens (as duas fontes do Atendimento), EXCETO a conversa de sistema.
            $enviadas = AutoReplyLog::withoutAccountScope()
                ->where('account_id', $accountId)
                ->where('remote_jid', '!=', SystemConversation::JID)
                ->delete();

            $recebidas = IncomingMessage::withoutAccountScope()
                ->where('account_id', $accountId)
                ->where('remote_jid', '!=', SystemConversation::JID)
                ->delete();

            $total = (int) $recebidas + (int) $enviadas;

            // Auditoria: rastro de uma ação destrutiva (quem, quando, quantas).
            // NUNCA o conteúdo das mensagens.
            SystemEvent::withoutAccountScope()->create([
                'account_id' => $accountId,
                'type' => 'conversas',
                'level' => 'warning',
                'title' => "Conversas apagadas em massa: {$total} mensagens (contatos preservados)",
                'detail' => [
                    'account_id' => $accountId,
                    'user_id' => $userId,
                    'mensagens_apagadas' => $total,
                    'recebidas' => (int) $recebidas,
                    'enviadas' => (int) $enviadas,
                ],
                'occurred_at' => now(),
            ]);

            return $total;
        });
    }

    /** Quantas mensagens seriam apagadas (para a confirmação). */
    public function count(int $accountId): int
    {
        $recebidas = IncomingMessage::withoutAccountScope()
            ->where('account_id', $accountId)->where('remote_jid', '!=', SystemConversation::JID)->count();
        $enviadas = AutoReplyLog::withoutAccountScope()
            ->where('account_id', $accountId)->where('remote_jid', '!=', SystemConversation::JID)->count();

        return $recebidas + $enviadas;
    }
}
