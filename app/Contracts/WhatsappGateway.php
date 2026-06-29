<?php

namespace App\Contracts;

use App\Whatsapp\IncomingMessageData;

/**
 * Contrato do gateway de WhatsApp. Abstrai o provedor (hoje Evolution) pra manter
 * a porta aberta pra trocar de driver depois sem mexer no resto do app.
 *
 * Camada 1: SO o lado de ENTRADA (normalizar webhook -> DTO). O envio e stub.
 */
interface WhatsappGateway
{
    /**
     * Normaliza o payload bruto do webhook em um DTO. Retorna null quando o evento
     * nao e uma mensagem (ex.: update de status, presenca) ou nao tem id de mensagem.
     */
    public function normalizeIncoming(array $payload): ?IncomingMessageData;

    /**
     * Envio de texto. STUB na Camada 1 — nao envia nada (lanca excecao).
     * Sera implementado na Camada 2.
     */
    public function sendText(string $instance, string $to, string $text): void;
}
