<?php

namespace App\Whatsapp;

use DateTimeImmutable;

/**
 * DTO normalizado de uma mensagem recebida, independente de provedor.
 * CH-1: `providerMessageId` (id da mensagem NO provedor — Evolution key.id,
 * Cloud API wamid). A coluna fisica segue `evolution_message_id` (CH-D4:
 * rename de coluna em producao adiado; legado semantico documentado).
 */
final class IncomingMessageData
{
    public function __construct(
        public readonly string $instance,
        public readonly string $providerMessageId,
        public readonly string $remoteJid,
        public readonly bool $fromMe,
        public readonly ?string $pushName,
        public readonly string $type,
        public readonly ?string $text,
        public readonly array $raw,
        public readonly DateTimeImmutable $receivedAt,
    ) {
    }
}
