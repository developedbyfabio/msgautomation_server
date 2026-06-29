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
