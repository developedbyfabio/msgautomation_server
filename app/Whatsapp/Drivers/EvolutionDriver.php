<?php

namespace App\Whatsapp\Drivers;

use App\Contracts\WhatsappGateway;
use App\Whatsapp\IncomingMessageData;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Driver da Evolution API (v2). Camada 1: so normaliza o webhook de entrada.
 *
 * Evento de "mensagem recebida" na v2: messages.upsert. Formato tipico do payload
 * por instancia:
 *   {
 *     "event": "messages.upsert",
 *     "instance": "fabio-pessoal",
 *     "data": {
 *       "key": { "remoteJid": "...@s.whatsapp.net", "fromMe": false, "id": "ABC123" },
 *       "pushName": "Fulano",
 *       "messageType": "conversation",
 *       "message": { "conversation": "oi" },
 *       "messageTimestamp": 1719700000
 *     }
 *   }
 */
class EvolutionDriver implements WhatsappGateway
{
    /** Eventos que representam mensagem recebida/registravel. */
    private const EVENTO_MENSAGEM = 'messages.upsert';

    public function normalizeIncoming(array $payload): ?IncomingMessageData
    {
        $evento = $this->normalizarNomeEvento($payload['event'] ?? null);
        if ($evento !== self::EVENTO_MENSAGEM) {
            return null;
        }

        $instance = $this->str($payload['instance'] ?? null);
        $data = $payload['data'] ?? null;

        // Em alguns casos data pode vir como lista de mensagens; pega a primeira.
        if (is_array($data) && array_is_list($data)) {
            $data = $data[0] ?? null;
        }
        if (! is_array($data)) {
            return null;
        }

        $key = is_array($data['key'] ?? null) ? $data['key'] : [];
        $messageId = $this->str($key['id'] ?? null);
        $remoteJid = $this->str($key['remoteJid'] ?? null);

        // Sem id de mensagem ou instance nao da pra garantir idempotencia -> ignora.
        if ($instance === null || $messageId === null || $remoteJid === null) {
            return null;
        }

        $type = $this->str($data['messageType'] ?? null) ?? $this->inferirTipo($data['message'] ?? null) ?? 'unknown';

        return new IncomingMessageData(
            instance: $instance,
            evolutionMessageId: $messageId,
            remoteJid: $remoteJid,
            fromMe: (bool) ($key['fromMe'] ?? false),
            pushName: $this->str($data['pushName'] ?? null),
            type: $type,
            text: $this->extrairTexto($data['message'] ?? null),
            raw: $payload,
            receivedAt: $this->timestamp($data['messageTimestamp'] ?? null),
        );
    }

    public function sendText(string $instance, string $to, string $text): void
    {
        // Camada 1 nao envia nada. Implementacao real fica pra Camada 2.
        throw new RuntimeException('Envio desabilitado na Camada 1 (somente recebimento).');
    }

    private function normalizarNomeEvento(mixed $evento): ?string
    {
        if (! is_string($evento)) {
            return null;
        }

        // A Evolution pode mandar "messages.upsert" ou "MESSAGES_UPSERT".
        return str_replace('_', '.', strtolower($evento));
    }

    private function inferirTipo(mixed $message): ?string
    {
        if (! is_array($message)) {
            return null;
        }

        return array_key_first($message) ?: null;
    }

    private function extrairTexto(mixed $message): ?string
    {
        if (! is_array($message)) {
            return null;
        }

        if (isset($message['conversation']) && is_string($message['conversation'])) {
            return $message['conversation'];
        }

        if (isset($message['extendedTextMessage']['text']) && is_string($message['extendedTextMessage']['text'])) {
            return $message['extendedTextMessage']['text'];
        }

        // Legenda de midia (imagem/video/documento).
        foreach (['imageMessage', 'videoMessage', 'documentMessage'] as $tipo) {
            if (isset($message[$tipo]['caption']) && is_string($message[$tipo]['caption'])) {
                return $message[$tipo]['caption'];
            }
        }

        return null;
    }

    private function timestamp(mixed $valor): DateTimeImmutable
    {
        $tz = new DateTimeZone(config('app.timezone'));

        if (is_numeric($valor)) {
            return (new DateTimeImmutable('@' . (int) $valor))->setTimezone($tz);
        }

        return new DateTimeImmutable('now', $tz);
    }

    private function str(mixed $valor): ?string
    {
        if (is_string($valor) && $valor !== '') {
            return $valor;
        }

        if (is_int($valor)) {
            return (string) $valor;
        }

        return null;
    }
}
