<?php

namespace App\Ai;

/**
 * Entrada do modo `conhecimento` (Fatia 2). MINIMIZACAO (regra dura da Camada 3):
 * so vai pro modelo a mensagem atual + as entradas candidatas da base com
 * sensibilidade low/medium E permitidas pro contato. NUNCA vai historico,
 * raw_payload, conteudo `high` ou valor de segredo — placeholders ({senha:nome})
 * seguem INTACTOS no conteudo; o valor real so e resolvido no envio (Sender).
 */
final class AiAnswerRequest
{
    /**
     * @param  array<int,array{id:int,title:string,content:string}>  $entries  so low/medium permitidas
     * @param  array<int,string>  $approvalTopics  temas que exigem aprovacao (o modelo marca needs_approval)
     */
    public function __construct(
        public readonly string $message,
        public readonly array $entries,
        public readonly array $approvalTopics = [],
    ) {
    }
}
