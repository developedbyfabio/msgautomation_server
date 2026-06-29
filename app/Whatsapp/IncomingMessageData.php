<?php

namespace App\Whatsapp;

use DateTimeImmutable;

/**
 * DTO normalizado de uma mensagem recebida, independente de provedor.
 */
final class IncomingMessageData
{
    public function __construct(
        public readonly string $instance,
        public readonly string $evolutionMessageId,
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
