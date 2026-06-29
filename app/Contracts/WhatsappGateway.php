<?php

namespace App\Contracts;

use App\Whatsapp\IncomingMessageData;
use App\Whatsapp\SentMessageData;

/**
 * Contrato do gateway de WhatsApp. Abstrai o provedor (hoje Evolution) pra manter
 * a porta aberta pra trocar de driver depois sem mexer no resto do app.
 */
interface WhatsappGateway
{
    /**
     * Normaliza o payload bruto do webhook em um DTO. Retorna null quando o evento
     * nao e uma mensagem (ex.: update de status, presenca) ou nao tem id de mensagem.
     */
    public function normalizeIncoming(array $payload): ?IncomingMessageData;

    /**
     * Envia uma mensagem de texto para um destinatario (numero ou jid).
     * Lanca App\Whatsapp\Exceptions\WhatsappSendException em falha de envio.
     *
     * IMPORTANTE: este metodo NAO aplica freios anti-ban — quem chama deve passar
     * pelos freios (App\Whatsapp\AutoReply\Sender). Aqui e so o transporte.
     */
    public function sendText(string $instance, string $to, string $text): SentMessageData;
}
