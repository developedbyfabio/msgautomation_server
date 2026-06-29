<?php

namespace App\Whatsapp\Drivers;

use App\Contracts\WhatsappGateway;
use App\Whatsapp\Exceptions\WhatsappSendException;
use App\Whatsapp\IncomingMessageData;
use App\Whatsapp\SentMessageData;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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

        // Catch-all: SEMPRE resolve um tipo (messageType -> inferido -> 'unknown') e
        // SEMPRE retorna o DTO pra qualquer messageType. Nada e descartado por tipo
        // (incl. reaction/sticker/location/poll/desconhecido) — so falta key.id/jid.
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

    public function sendText(string $instance, string $to, string $text): SentMessageData
    {
        $base = rtrim((string) config('services.evolution.base_url'), '/');
        $key = (string) config('services.evolution.api_key');

        // A Evolution v2.3.7 aceita numero ou jid no campo "number"; normalizamos pra digitos.
        $number = str_contains($to, '@') ? Str::before($to, '@') : $to;

        $resp = Http::baseUrl($base)
            ->withHeaders(['apikey' => $key])
            ->acceptJson()
            ->timeout(20)
            ->post("/message/sendText/{$instance}", [
                'number' => $number,
                'text' => $text,
            ]);

        if ($resp->failed()) {
            throw new WhatsappSendException("Evolution sendText falhou (HTTP {$resp->status()}).");
        }

        $json = $resp->json() ?? [];

        return new SentMessageData(
            providerMessageId: data_get($json, 'key.id'),
            status: $resp->status(),
            raw: is_array($json) ? $json : [],
        );
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

        // Pula wrappers de metadados pra pegar o tipo REAL (ex.: messageContextInfo
        // costuma vir junto de imageMessage/extendedTextMessage).
        foreach ($message as $chave => $_) {
            if (! in_array($chave, ['messageContextInfo'], true)) {
                return (string) $chave;
            }
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
