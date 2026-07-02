<?php

namespace App\Channels\CloudApi;

use App\Channels\ChannelCapabilities;
use App\Channels\ChannelProvider;
use App\Models\Channel;
use App\Models\Contact;
use App\Whatsapp\Exceptions\WhatsappSendException;
use App\Whatsapp\IncomingMessageData;
use App\Whatsapp\SentMessageData;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * CH-2 — WhatsApp Cloud API OFICIAL da Meta, reativo-only (CH-D3).
 *
 * Contrato do CH-1, segunda implementacao. O dominio nao muda: o adaptador
 * devolve o MESMO IncomingMessageData (wa_id -> JID canonico na borda) e o
 * envio e transporte puro (freios ficam no Sender).
 *
 * Credenciais POR CANAL (cifradas): access_token, phone_number_id, waba_id,
 * verify_token, app_secret. `channels.instance` guarda o phone_number_id (a
 * chave de roteamento — mesmo papel do nome da instancia na Evolution).
 *
 * NOTA de verificacao (CH-2): a doc oficial da Meta NAO era alcancavel deste
 * ambiente (dominios Meta bloqueados na rede) — implementado pelo desenho
 * CH-0 + contrato publico consolidado da Cloud API, com a VERSAO do Graph
 * CONFIGURAVEL (services.cloud_api.graph_version) e validacao REAL na Parte B.
 */
class CloudApiProvider implements ChannelProvider
{
    public function key(): string
    {
        return 'cloud_api';
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(
            grupos: false,                      // nao existem eventos de grupo no oficial
            mensagemLivreForaDaJanela: false,   // 24h da Meta: livre so na janela
            proativaLivre: false,               // proativa = template (CH-3); 10o freio segura
            qr: false,                          // conexao por credenciais, sem QR
            template: false,                    // vira true na CH-3
        );
    }

    /** @return array{access_token:string,phone_number_id:string,waba_id:string,verify_token:string,app_secret:string} */
    public function credentialsFor(Channel $channel): array
    {
        $c = (array) ($channel->credentials ?? []);

        return [
            'access_token' => (string) ($c['access_token'] ?? ''),
            'phone_number_id' => (string) (($c['phone_number_id'] ?? null) ?: $channel->instance),
            'waba_id' => (string) ($c['waba_id'] ?? ''),
            'verify_token' => (string) ($c['verify_token'] ?? ''),
            'app_secret' => (string) ($c['app_secret'] ?? ''),
        ];
    }

    // ---- webhook -------------------------------------------------------------------

    /**
     * GET  = challenge da Meta: hub.mode=subscribe + hub.verify_token do canal.
     * POST = assinatura X-Hub-Signature-256 ("sha256=" + HMAC-SHA256 do CORPO CRU
     * com o app secret do canal), comparacao em tempo constante. Invalida = 401
     * (o middleware aborta) + log — NUNCA processa.
     */
    public function verifyWebhook(Request $request, Channel $channel): bool
    {
        $cred = $this->credentialsFor($channel);

        if ($request->isMethod('GET')) {
            $ok = $request->query('hub_mode') === 'subscribe'
                && $cred['verify_token'] !== ''
                && hash_equals($cred['verify_token'], (string) $request->query('hub_verify_token', ''));
            if (! $ok) {
                Log::warning('Cloud API: challenge de webhook recusado.', ['channel' => $channel->id]);
            }

            return $ok;
        }

        $assinatura = (string) $request->header('X-Hub-Signature-256', '');
        if ($cred['app_secret'] === '' || ! str_starts_with($assinatura, 'sha256=')) {
            Log::warning('Cloud API: POST sem assinatura valida.', ['channel' => $channel->id]);

            return false;
        }

        $esperada = hash_hmac('sha256', (string) $request->getContent(), $cred['app_secret']);
        $ok = hash_equals($esperada, substr($assinatura, 7));
        if (! $ok) {
            Log::warning('Cloud API: assinatura HMAC invalida — payload descartado.', ['channel' => $channel->id]);
        }

        return $ok;
    }

    /**
     * Adaptador do payload da Meta pro DTO NEUTRO do dominio:
     *   entry[].changes[].value.{metadata.phone_number_id, contacts[], messages[]}
     * wa_id vira JID canonico na BORDA ({wa_id}@s.whatsapp.net) — o dominio
     * inteiro segue falando JID. Statuses (delivered/read) sao ignorados com log
     * leve (horizonte D5). Tipos nao-texto passam pelo catch-all (nunca descarta).
     */
    public function normalizeIncoming(array $payload): ?IncomingMessageData
    {
        $value = data_get($payload, 'entry.0.changes.0.value');
        if (! is_array($value)) {
            return null;
        }

        $phoneNumberId = (string) data_get($value, 'metadata.phone_number_id', '');

        // Statuses/read-receipts: fora do escopo (D5) — log leve, sem processar.
        if (! isset($value['messages']) && isset($value['statuses'])) {
            Log::debug('Cloud API: payload de status ignorado (horizonte D5).', ['phone_number_id' => $phoneNumberId]);

            return null;
        }

        $msg = data_get($value, 'messages.0');
        if (! is_array($msg)) {
            return null;
        }

        $messageId = (string) ($msg['id'] ?? '');
        $from = (string) ($msg['from'] ?? '');
        if ($phoneNumberId === '' || $messageId === '' || $from === '') {
            return null; // sem chave de roteamento/idempotencia nao processa
        }

        $type = (string) ($msg['type'] ?? 'unknown');

        return new IncomingMessageData(
            instance: $phoneNumberId, // channels.instance do canal cloud = phone_number_id
            providerMessageId: $messageId, // wamid.* na coluna legada de idempotencia
            remoteJid: $this->canonicalJid($from, $phoneNumberId), // wa_id -> JID canonico NA BORDA (9o digito BR tratado)
            fromMe: false, // echo proprio nao e entregue em `messages`
            pushName: $this->str(data_get($value, 'contacts.0.profile.name')),
            type: $type !== '' ? $type : 'unknown',
            text: $this->extrairTexto($msg, $type),
            raw: $payload,
            receivedAt: $this->timestamp($msg['timestamp'] ?? null),
        );
    }

    // ---- envio (transporte puro) -------------------------------------------------------

    public function sendText(Channel $channel, string $to, string $text, ?string $replyTo = null): SentMessageData
    {
        $cred = $this->credentialsFor($channel);
        if ($cred['access_token'] === '' || $cred['phone_number_id'] === '') {
            throw new WhatsappSendException('Cloud API: canal sem credenciais (access_token/phone_number_id).');
        }

        $numero = str_contains($to, '@') ? Str::before($to, '@') : $to;

        $body = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $numero,
            'type' => 'text',
            'text' => ['body' => $text],
        ];
        // CH-2 Parte B: resposta reativa vira reply CONTEXTUAL (bolha citando a
        // mensagem do cliente) quando temos o wamid do inbound.
        if ($replyTo !== null && $replyTo !== '') {
            $body['context'] = ['message_id' => $replyTo];
        }

        $resp = Http::baseUrl($this->graphBase())
            ->withToken($cred['access_token'])
            ->acceptJson()
            ->timeout(20)
            ->post('/' . $this->graphVersion() . '/' . $cred['phone_number_id'] . '/messages', $body);

        if ($resp->failed()) {
            // Mapeia sem vazar token: credencial (401/403), limite (429), janela
            // fechada/erro de negocio da Meta (400 com code no corpo).
            $motivo = match (true) {
                in_array($resp->status(), [401, 403], true) => 'credencial invalida/expirada',
                $resp->status() === 429 => 'limite de requisicoes da Meta',
                default => 'erro ' . $resp->status() . ' (code Meta: ' . (data_get($resp->json(), 'error.code') ?? '?') . ')',
            };
            Log::warning('Cloud API: envio falhou.', ['channel' => $channel->id, 'motivo' => $motivo]);

            throw new WhatsappSendException("Cloud API sendText falhou: {$motivo}.");
        }

        return new SentMessageData(
            providerMessageId: data_get($resp->json(), 'messages.0.id'),
            status: $resp->status(),
            raw: (array) $resp->json(),
        );
    }

    // ---- conexao -----------------------------------------------------------------------

    /** Sanidade LEVE sob demanda (botao "verificar" — nunca em loop/poll). */
    public function connectionState(?Channel $channel = null): string
    {
        if ($channel === null) {
            return 'unknown';
        }
        $cred = $this->credentialsFor($channel);
        if ($cred['access_token'] === '' || $cred['phone_number_id'] === '') {
            return 'disconnected';
        }

        try {
            $resp = Http::baseUrl($this->graphBase())
                ->withToken($cred['access_token'])
                ->acceptJson()->timeout(10)
                ->get('/' . $this->graphVersion() . '/' . $cred['phone_number_id'], ['fields' => 'id']);
        } catch (\Throwable) {
            return 'unknown';
        }

        return match (true) {
            $resp->successful() => 'connected',
            in_array($resp->status(), [401, 403], true) => 'disconnected',
            default => 'unknown',
        };
    }

    // ---- helpers ----------------------------------------------------------------------------

    /**
     * CH-2 Parte B — normalizacao de TELEFONE BR (complementar ao MATCH-1, que e
     * de texto). Comportamento documentado da Meta: em numeros BR o prefixo/9o
     * digito do wa_id pode vir MODIFICADO (celular com 9 chega sem, ou vice-versa).
     * Regra conservadora: so troca pra VARIANTE se o contato JA EXISTE com ela na
     * conta do canal e NAO existe com a forma recebida — nunca duplica contato,
     * nunca inventa numero. Sem match de variante: mantem o que a Meta mandou.
     */
    private function canonicalJid(string $waId, string $phoneNumberId): string
    {
        $jid = $waId . '@s.whatsapp.net';

        $variante = null;
        if (preg_match('/^55(\d{2})(\d{8})$/', $waId, $m)) {
            $variante = '55' . $m[1] . '9' . $m[2] . '@s.whatsapp.net'; // sem 9 -> com 9
        } elseif (preg_match('/^55(\d{2})9(\d{8})$/', $waId, $m)) {
            $variante = '55' . $m[1] . $m[2] . '@s.whatsapp.net'; // com 9 -> sem 9
        }
        if ($variante === null) {
            return $jid;
        }

        $channel = Channel::withoutAccountScope()->where('instance', $phoneNumberId)->first();
        if ($channel === null) {
            return $jid; // instancia desconhecida: o job descarta adiante
        }

        $existentes = Contact::withoutAccountScope()
            ->where('account_id', $channel->account_id)
            ->whereIn('remote_jid', [$jid, $variante])
            ->pluck('remote_jid');

        return (! $existentes->contains($jid) && $existentes->contains($variante)) ? $variante : $jid;
    }

    private function graphBase(): string
    {
        return rtrim((string) config('services.cloud_api.graph_base'), '/');
    }

    private function graphVersion(): string
    {
        return trim((string) config('services.cloud_api.graph_version'), '/');
    }

    private function extrairTexto(array $msg, string $type): ?string
    {
        if ($type === 'text') {
            $body = data_get($msg, 'text.body');

            return is_string($body) ? $body : null;
        }

        // Legenda de midia (mesmo espirito do catch-all da Evolution).
        foreach (['image', 'video', 'document'] as $midia) {
            $caption = data_get($msg, $midia . '.caption');
            if (is_string($caption) && $caption !== '') {
                return $caption;
            }
        }

        // interactive/button: o texto util da resposta do usuario.
        return $this->str(data_get($msg, 'button.text'))
            ?? $this->str(data_get($msg, 'interactive.button_reply.title'))
            ?? $this->str(data_get($msg, 'interactive.list_reply.title'));
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
        return is_string($valor) && $valor !== '' ? $valor : null;
    }
}
