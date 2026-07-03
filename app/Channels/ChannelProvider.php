<?php

namespace App\Channels;

use App\Models\Channel;
use App\Whatsapp\IncomingMessageData;
use App\Whatsapp\SentMessageData;
use Illuminate\Http\Request;

/**
 * CH-1 — contrato de PROVEDOR DE CANAL (doc 10). O dominio nao sabe qual
 * provedor atende um canal: ele pergunta ao provider resolvido pelo
 * ProviderRegistry. A Cloud API (CH-2) e SO uma segunda implementacao.
 *
 * Invariantes:
 *  - sendText e TRANSPORTE puro — freios/log/segredo ficam no Sender (unico);
 *  - normalizeIncoming devolve o MESMO IncomingMessageData pros dois provedores
 *    (o dominio e alimentado identico; so o adaptador conhece o formato);
 *  - verifyWebhook valida a ORIGEM pro canal ja resolvido pela rota/token;
 *  - capabilities() declara o que o canal suporta — quem consome consulta.
 */
interface ChannelProvider
{
    /** Chave canonica ('evolution' | 'cloud_api'). Espelho de channels.provider. */
    public function key(): string;

    public function capabilities(): ChannelCapabilities;

    /**
     * Envia texto livre pelo canal. NAO aplica freios (quem chama e o Sender).
     * Lanca WhatsappSendException em falha de transporte.
     *
     * CH-2 Parte B: $replyTo opcional = providerMessageId da mensagem RECEBIDA a
     * que este envio responde. Provider com reply contextual usa (cloud_api:
     * objeto `context.message_id` com o wamid); quem nao suporta IGNORA.
     */
    public function sendText(Channel $channel, string $to, string $text, ?string $replyTo = null): SentMessageData;

    /**
     * Prompt 04 — envia IMAGEM (transporte puro; freios no Sender, como o texto).
     * $filePath e caminho ABSOLUTO local; $caption opcional. Cada provider tem a
     * sua mecanica: Evolution manda base64 no sendMedia; Cloud API faz upload
     * (/{phone_number_id}/media -> media_id) e envia a mensagem referenciando o id.
     * Lanca WhatsappSendException em falha de transporte.
     */
    public function sendImage(Channel $channel, string $to, string $filePath, string $mime, ?string $caption = null, ?string $replyTo = null): SentMessageData;

    /** Valida a origem do webhook pro canal resolvido (token / HMAC no CH-2). */
    public function verifyWebhook(Request $request, Channel $channel): bool;

    /** Adapta o payload bruto do provedor pro DTO neutro (null = nao-mensagem). */
    public function normalizeIncoming(array $payload): ?IncomingMessageData;

    /** Estado normalizado da conexao: connected | connecting | disconnected | unknown. */
    public function connectionState(?Channel $channel = null): string;
}
