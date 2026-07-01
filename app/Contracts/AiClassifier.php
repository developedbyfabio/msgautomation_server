<?php

namespace App\Contracts;

use App\Ai\AiClassification;
use App\Ai\AiClassificationRequest;

/**
 * Contrato do classificador de intencao (Camada 3). Abstrai o provedor (hoje Gemini)
 * pra manter a porta aberta pra trocar de modelo/driver sem mexer no resto.
 *
 * NAO gera texto de resposta — so CLASSIFICA a intencao contra as regras candidatas.
 * Em erro/cota/resposta invalida, DEVE retornar AiClassification::unknown() (nunca
 * lanca, nunca "chuta" uma resposta). Quem chama decide (responder/escalar/silenciar).
 */
interface AiClassifier
{
    public function classify(AiClassificationRequest $request): AiClassification;
}
