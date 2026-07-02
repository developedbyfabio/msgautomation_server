<?php

namespace App\Channels\Evolution;

use App\Channels\ChannelCapabilities;
use App\Channels\ChannelProvider;
use App\Contracts\WhatsappGateway;
use App\Models\Channel;
use App\Whatsapp\Exceptions\WhatsappSendException;
use App\Whatsapp\IncomingMessageData;
use App\Whatsapp\SentMessageData;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * CH-1 — TODA a superficie Evolution vive aqui (doc 10): envio (sendText),
 * verificacao do webhook (token por canal do MT-0), normalizacao do payload
 * (adaptador messages.upsert, catch-all de tipo — absorvido do antigo
 * EvolutionDriver) e administracao da instancia (QR/connect/logout/estado,
 * via EvolutionApi — detalhe INTERNO do provider, exposto so pelo api()).
 *
 * Credenciais: channels.credentials (cifrado) quando preenchido; FALLBACK no
 * env atual (comportamento de hoje — MT-2 preenche e remove o fallback).
 *
 * Implementa tambem o WhatsappGateway (so normalizeIncoming) como ALIAS de
 * compatibilidade: o job de webhook segue recebendo o mesmo contrato — o alias
 * morre quando a rota passar a resolver o provider (CH-2).
 */
class EvolutionProvider implements ChannelProvider, WhatsappGateway
{
    /** Evento de "mensagem recebida" na Evolution v2. */
    private const EVENTO_MENSAGEM = 'messages.upsert';

    public function key(): string
    {
        return 'evolution';
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(
            grupos: true,
            mensagemLivreForaDaJanela: true, // nao-oficial: sem janela de 24h
            proativaLivre: true,             // risco de ban administrado pelos freios
            qr: true,
            template: false,
        );
    }

    // ---- credenciais (fallback ADITIVO no env — MT-2 remove) ---------------------

    /**
     * Credenciais efetivas do canal: channels.credentials quando preenchido,
     * senao o env atual (identico ao comportamento pre-CH-1). NUNCA logar.
     *
     * @return array{base_url: string, apikey: string, instance: string}
     */
    public function credentialsFor(?Channel $channel = null): array
    {
        $cred = (array) ($channel?->credentials ?? []);

        return [
            'base_url' => (string) (($cred['base_url'] ?? null) ?: config('services.evolution.base_url')),
            'apikey' => (string) (($cred['apikey'] ?? null) ?: config('services.evolution.api_key')),
            'instance' => (string) (($cred['instance'] ?? null) ?: ($channel->instance ?? config('services.evolution.instance'))),
        ];
    }

    /**
     * Cliente de ADMINISTRACAO da instancia (QR/connect/logout/webhook/grupos).
     * Exclusivo-Evolution: so telas de conexao, comandos e resolver de grupo
     * pedem isso — o dominio usa apenas o contrato ChannelProvider.
     */
    public function api(?Channel $channel = null): EvolutionApi
    {
        $c = $this->credentialsFor($channel);

        return new EvolutionApi($c['base_url'], $c['apikey'], $c['instance']);
    }

    // ---- envio (transporte puro; freios ficam no Sender) --------------------------

    public function sendText(Channel $channel, string $to, string $text): SentMessageData
    {
        $c = $this->credentialsFor($channel);

        // A Evolution v2.3.7 aceita numero ou jid no campo "number"; normalizamos pra digitos.
        $number = str_contains($to, '@') ? Str::before($to, '@') : $to;

        $resp = Http::baseUrl(rtrim($c['base_url'], '/'))
            ->withHeaders(['apikey' => $c['apikey']])
            ->acceptJson()
            ->timeout(20)
            ->post("/message/sendText/{$c['instance']}", [
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

    // ---- webhook -------------------------------------------------------------------

    /** Evolution: a origem e o TOKEN por canal (MT-0). Comparacao em tempo constante. */
    public function verifyWebhook(Request $request, Channel $channel): bool
    {
        $token = (string) $request->route('token', '');

        return $token !== ''
            && $channel->webhook_token !== null
            && hash_equals((string) $channel->webhook_token, $token);
    }

    /**
     * Adaptador do payload da Evolution v2 (messages.upsert) pro DTO neutro.
     * Catch-all: SEMPRE resolve um tipo e SEMPRE devolve o DTO pra qualquer
     * messageType — nada e descartado por tipo, so por falta de key.id/jid.
     */
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
            providerMessageId: $messageId,
            remoteJid: $remoteJid,
            fromMe: (bool) ($key['fromMe'] ?? false),
            pushName: $this->str($data['pushName'] ?? null),
            type: $type,
            text: $this->extrairTexto($data['message'] ?? null),
            raw: $payload,
            receivedAt: $this->timestamp($data['messageTimestamp'] ?? null),
        );
    }

    // ---- conexao ---------------------------------------------------------------------

    /** Estado normalizado (a tela de QR usa o estado CRU via api() — sem mudanca). */
    public function connectionState(?Channel $channel = null): string
    {
        try {
            $resp = $this->api($channel)->connectionState();
            if (! $resp->successful()) {
                return 'unknown';
            }
            $estado = (string) (data_get($resp->json(), 'instance.state') ?? data_get($resp->json(), 'state') ?? '');
        } catch (\Throwable) {
            return 'unknown';
        }

        return match ($estado) {
            'open' => 'connected',
            'connecting' => 'connecting',
            'close' => 'disconnected',
            default => 'unknown',
        };
    }

    // ---- helpers do adaptador (absorvidos do EvolutionDriver) --------------------------

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
