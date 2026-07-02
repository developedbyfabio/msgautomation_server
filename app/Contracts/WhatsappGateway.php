<?php

namespace App\Contracts;

use App\Whatsapp\IncomingMessageData;

/**
 * DEPRECADO (CH-1) — alias de compatibilidade do webhook. O contrato real de
 * provedor e App\Channels\ChannelProvider (envio/verificacao/conexao/capacidades,
 * resolvido POR CANAL pelo ProviderRegistry). Este contrato sobrevive APENAS
 * como a porta de normalizacao que o job de webhook ja recebia — o binding
 * resolve o EvolutionProvider. Morre quando a ROTA do webhook passar a resolver
 * o provider (CH-2 cria a segunda rota; ai o job recebe o provider da rota).
 */
interface WhatsappGateway
{
    /** Normaliza o payload bruto do webhook (null = evento que nao e mensagem). */
    public function normalizeIncoming(array $payload): ?IncomingMessageData;
}
