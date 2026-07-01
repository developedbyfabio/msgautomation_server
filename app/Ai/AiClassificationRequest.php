<?php

namespace App\Ai;

/**
 * Entrada do classificador. MINIMIZACAO (regra dura da Camada 3): so vai pro modelo a
 * mensagem atual + as intencoes candidatas (gatilhos e frases-exemplo). NUNCA vai
 * historico, raw_payload, texto de resposta ou valor de segredo.
 */
final class AiClassificationRequest
{
    /**
     * @param  array<int,array{rule_id:int,triggers:array<int,string>,examples:array<int,string>}>  $candidates
     * @param  array<int,string>  $approvalTopics  temas que exigem aprovacao (o modelo marca needs_approval)
     */
    public function __construct(
        public readonly string $message,
        public readonly array $candidates,
        public readonly array $approvalTopics = [],
    ) {
    }
}
