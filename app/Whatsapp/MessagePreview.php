<?php

namespace App\Whatsapp;

/**
 * Preview de exibicao por tipo de mensagem (S5). Deriva icone/label/emoji/legenda
 * de (type, text, raw_payload) — SO pra exibir; nao toca em matcher/freios.
 *
 * Retorno consistente:
 *   ['plain' => ?string, 'icon' => ?string, 'emoji' => ?string, 'label' => ?string, 'caption' => ?string]
 * - texto puro: 'plain' preenchido (o resto null).
 * - midia/outros: 'label' (+ 'icon' ou 'emoji') e 'caption' opcional.
 */
class MessagePreview
{
    /** Preview de texto puro (mensagens enviadas/logs). */
    public static function plain(?string $text): array
    {
        return ['plain' => $text ?? '', 'icon' => null, 'emoji' => null, 'label' => null, 'caption' => null];
    }

    /**
     * Midia recebida — Fatia 1: miniatura JPEG EMBUTIDA no payload
     * (Evolution: `imageMessage.jpegThumbnail`), devolvida como data URI.
     * NAO baixa/descriptografa nada — so le o que o webhook ja trouxe.
     *
     * Best-effort: qualquer forma inesperada -> null (a bolha cai no rotulo).
     * A Cloud API nao embute thumbnail no webhook, entao la retorna null e a
     * imagem cheia fica pra Fatia 2 (download por media_id).
     *
     * Formas aceitas do jpegThumbnail:
     *  - array de bytes puro `[255,216,255,...]` (Evolution/Baileys — o caso real);
     *  - Buffer serializado `{type:'Buffer', data:[...]}`;
     *  - string ja em base64 (fallback defensivo).
     */
    public static function thumbnail(array $raw): ?string
    {
        $bin = self::thumbnailBinary($raw);

        return $bin !== null ? 'data:image/jpeg;base64,' . base64_encode($bin) : null;
    }

    /**
     * Prompt 13 (Frente 3) — checagem BARATA de que ha miniatura embutida (so
     * presenca, sem reconstruir bytes): o thread() decide se emite a URL do thumb
     * sem carregar base64 no HTML do poll. A validacao real fica na rota.
     */
    public static function hasThumbnail(array $raw): bool
    {
        return ! empty(data_get(self::msgNode($raw), 'imageMessage.jpegThumbnail'));
    }

    /**
     * Prompt 13 (Frente 3) — os BYTES crus do jpegThumbnail (pra servir por URL,
     * tirando o base64 do HTML do poll). Mesma extracao/validacao do thumbnail(),
     * so que devolve o binario JPEG em vez do data URI. null = sem miniatura valida.
     */
    public static function thumbnailBinary(array $raw): ?string
    {
        $t = data_get(self::msgNode($raw), 'imageMessage.jpegThumbnail');

        // Ja veio base64 (serializacao alternativa) — decodifica.
        if (is_string($t)) {
            if ($t === '') {
                return null;
            }
            $bin = base64_decode($t, true);

            return ($bin !== false && strncmp($bin, "\xFF\xD8\xFF", 3) === 0) ? $bin : null;
        }

        // Buffer serializado: {type:'Buffer', data:[...]}.
        if (is_array($t) && isset($t['data']) && is_array($t['data'])) {
            $t = $t['data'];
        }

        if (! is_array($t) || $t === []) {
            return null;
        }

        // Reconstitui o binario de bytes (0..255). Qualquer coisa fora disso: aborta.
        $bin = '';
        foreach ($t as $b) {
            if (! is_int($b) || $b < 0 || $b > 255) {
                return null;
            }
            $bin .= chr($b);
        }

        // Confere a assinatura JPEG (FF D8 FF) — nao vira <img> quebrada com lixo.
        return strncmp($bin, "\xFF\xD8\xFF", 3) === 0 ? $bin : null;
    }

    public static function for(string $type, ?string $text, array $raw = []): array
    {
        $base = ['plain' => null, 'icon' => null, 'emoji' => null, 'label' => null, 'caption' => null];
        $msg = self::msgNode($raw);
        $caption = ($text !== null && $text !== '') ? $text : null;

        return match ($type) {
            'conversation', 'extendedTextMessage' => ['plain' => $text ?? ''] + $base,

            'imageMessage' => ['icon' => 'photo', 'label' => 'Imagem', 'caption' => $caption] + $base,
            'videoMessage' => ['icon' => 'video-camera', 'label' => 'Video', 'caption' => $caption] + $base,
            'audioMessage', 'pttMessage' => ['icon' => 'microphone', 'label' => 'Audio'] + $base,
            'stickerMessage' => ['icon' => 'face-smile', 'label' => 'Figurinha'] + $base,
            'documentMessage', 'documentWithCaptionMessage' => [
                'icon' => 'document',
                'label' => self::str($msg, ['documentMessage.fileName', 'documentMessage.title', 'documentWithCaptionMessage.message.documentMessage.fileName']) ?? 'Documento',
                'caption' => $caption,
            ] + $base,
            'locationMessage', 'liveLocationMessage' => ['icon' => 'map-pin', 'label' => 'Localizacao'] + $base,
            'contactMessage', 'contactsArrayMessage' => [
                'icon' => 'user',
                'label' => self::str($msg, ['contactMessage.displayName']) ?? 'Contato',
            ] + $base,
            'pollCreationMessage', 'pollCreationMessageV2', 'pollCreationMessageV3' => [
                'icon' => 'chart-bar',
                'label' => self::str($msg, ['pollCreationMessage.name', 'pollCreationMessageV3.name']) ?? 'Enquete',
            ] + $base,
            'reactionMessage' => [
                'emoji' => self::str($msg, ['reactionMessage.text']) ?: null,
                'label' => 'reagiu',
            ] + $base,

            default => ['icon' => 'document-text', 'label' => '[' . $type . ']'] + $base,
        };
    }

    /** Resolve o nó message do payload (data.message ou data.0.message). */
    private static function msgNode(array $raw): array
    {
        $m = data_get($raw, 'data.message');
        if (! is_array($m)) {
            $m = data_get($raw, 'data.0.message');
        }

        return is_array($m) ? $m : [];
    }

    /** Primeiro caminho não-vazio (string) entre os candidatos. */
    private static function str(array $msg, array $paths): ?string
    {
        foreach ($paths as $p) {
            $v = data_get($msg, $p);
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }

        return null;
    }
}
