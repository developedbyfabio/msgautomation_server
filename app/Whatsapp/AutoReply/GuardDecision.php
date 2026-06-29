<?php

namespace App\Whatsapp\AutoReply;

/**
 * Resultado de uma avaliacao de freios: liberar ou parar (com motivo).
 */
final class GuardDecision
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason,
    ) {
    }

    public static function allow(): self
    {
        return new self(true, null);
    }

    public static function block(string $reason): self
    {
        return new self(false, $reason);
    }
}
