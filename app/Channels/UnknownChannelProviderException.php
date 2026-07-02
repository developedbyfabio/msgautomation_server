<?php

namespace App\Channels;

/** CH-1 — provider desconhecido em channels.provider: falha ALTO (nunca silencioso). */
class UnknownChannelProviderException extends \RuntimeException
{
    public function __construct(string $key)
    {
        parent::__construct("Provedor de canal desconhecido: '{$key}'.");
    }
}
