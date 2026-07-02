<?php

namespace App\Contracts;

use App\Ai\AiAnswer;
use App\Ai\AiAnswerRequest;
use App\Ai\AiClassification;
use App\Ai\AiClassificationRequest;

/**
 * Contrato do classificador de intencao (Camada 3). Abstrai o provedor (hoje Gemini)
 * pra manter a porta aberta pra trocar de modelo/driver sem mexer no resto.
 *
 * classify() NAO gera texto de resposta — so CLASSIFICA a intencao contra as regras
 * candidatas. answer() (Fatia 2, modo `conhecimento`) responde FUNDAMENTADO SO no
 * conteudo fornecido da base (low/medium) — sem grounding, e "nao sei".
 * Em erro/cota/resposta invalida, ambos DEVEM retornar ::unknown() (nunca lancam,
 * nunca "chutam"). Quem chama decide (responder/escalar/silenciar).
 */
interface AiClassifier
{
    public function classify(AiClassificationRequest $request): AiClassification;

    public function answer(AiAnswerRequest $request): AiAnswer;
}
