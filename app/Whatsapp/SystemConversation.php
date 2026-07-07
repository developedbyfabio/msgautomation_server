<?php

namespace App\Whatsapp;

use App\Models\Contact;
use App\Models\IncomingMessage;

/**
 * Conversa de SISTEMA "Alertas de Infraestrutura" (uma por conta): agrega no
 * Atendimento, como histórico READ-ONLY, os alertas de servidor de TODOS os
 * servidores da conta. É só EXIBIÇÃO — isolada do pipeline de automação.
 *
 * Isolamento por construção:
 *  - JID SINTÉTICO (@sistema.msgauto): nenhuma mensagem real do WhatsApp
 *    (@s.whatsapp.net / @g.us) atinge esse JID — a conversa nunca entra pelo
 *    webhook, logo nunca é avaliada por matching/robô/FlowEngine.
 *  - As mensagens são inseridas DIRETO (record), sem disparar evento de
 *    domínio — logo nunca alimentam Kanban/IA/opt-out.
 *  - O contato é marcado is_system=true e EXCLUÍDO dos pontos que consultam
 *    contatos (Campanhas, Clientes, métricas). isSystemJid() é o espelho barato
 *    do isGroup() (@g.us) para os pontos que só têm o JID.
 */
class SystemConversation
{
    /** Domínio sintético — nenhum número real do WhatsApp termina assim. */
    public const DOMAIN = '@sistema.msgauto';

    public const JID = 'alertas-infra'.self::DOMAIN;

    public const NAME = 'Alertas de Infraestrutura';

    public const INSTANCE = 'sistema';

    /** Espelho barato do isGroup() (@g.us): true se o JID é de conversa de sistema. */
    public static function isSystemJid(?string $jid): bool
    {
        return $jid !== null && str_ends_with($jid, self::DOMAIN);
    }

    /** Garante o contato de sistema da conta (idempotente; is_system + robô off). */
    public function ensureContact(int $accountId): Contact
    {
        return Contact::withoutAccountScope()->firstOrCreate(
            ['account_id' => $accountId, 'remote_jid' => self::JID],
            [
                'push_name' => self::NAME,
                'is_system' => true,
                'auto_reply_mode' => 'off',
                'proactive_opt_in' => false,
                'saved' => false,
            ],
        );
    }

    /**
     * Grava UMA mensagem de alerta na conversa de sistema da conta, visível no
     * Atendimento (bolha "recebida"). Idempotente por $ref (unique
     * instance+evolution_message_id). NÃO dispara evento — não contamina o
     * pipeline. NÃO envia nada pelo WhatsApp (o envio é do SendServerAlert).
     */
    public function record(int $accountId, string $text, string $ref): IncomingMessage
    {
        $this->ensureContact($accountId);

        return IncomingMessage::withoutAccountScope()->firstOrCreate(
            ['instance' => self::INSTANCE, 'evolution_message_id' => $ref],
            [
                'account_id' => $accountId,
                'remote_jid' => self::JID,
                'from_me' => false,
                'push_name' => self::NAME,
                'type' => 'conversation',
                'text' => $text,
                'raw_payload' => ['system' => true],
                'received_at' => now(),
            ],
        );
    }
}
