<?php

namespace App\Channels\CloudApi;

use App\Models\Channel;

/**
 * Prompt 24a — resultado neutro de SaveCloudChannel, apresentavel por CLI e UI.
 *  - ok=false + error: falha de validacao/anti-swap (nada persistido);
 *  - ok=true + channel: canal criado/atualizado (verifyGerado = verify_token gerado agora);
 *  - warning: aviso NAO bloqueante (ex.: access_token sem "EAA").
 */
final class SaveCloudChannelResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?Channel $channel = null,
        public readonly ?string $error = null,
        public readonly bool $verifyGerado = false,
        public readonly ?string $warning = null,
    ) {
    }

    public static function fail(string $error): self
    {
        return new self(ok: false, error: $error);
    }

    public static function success(Channel $channel, bool $verifyGerado, ?string $warning = null): self
    {
        return new self(ok: true, channel: $channel, verifyGerado: $verifyGerado, warning: $warning);
    }
}
