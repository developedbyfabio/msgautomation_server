<?php

namespace App\Whatsapp;

/**
 * Resultado normalizado de um envio, independente de provedor.
 */
final class SentMessageData
{
    public function __construct(
        public readonly ?string $providerMessageId,
        public readonly int $status,
        public readonly array $raw,
    ) {
    }
}
