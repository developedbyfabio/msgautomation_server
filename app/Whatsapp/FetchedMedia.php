<?php

namespace App\Whatsapp;

/**
 * Prompt 13 — binario de midia RECEBIDA ja resolvido por um provider
 * (Evolution: base64 do endpoint proprio; Cloud: download da Graph). Neutro:
 * o job de armazenamento nao sabe de qual canal veio.
 */
final class FetchedMedia
{
    public function __construct(
        public readonly string $binary,
        public readonly string $mime,
        public readonly ?string $filename = null,
    ) {
    }
}
