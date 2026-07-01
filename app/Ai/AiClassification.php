<?php

namespace App\Ai;

/**
 * Saida do classificador (DTO). `unknown=true` quando o modelo falhou/estourou cota/
 * devolveu algo invalido — quem chama trata como "nao sei" (silencia e loga), nunca
 * envia. `matchedRuleId` deve ser um dos ids candidatos (validado por quem chama).
 */
final class AiClassification
{
    public function __construct(
        public readonly string $intent,
        public readonly float $confidence,
        public readonly ?int $matchedRuleId,
        public readonly bool $shouldReply,
        public readonly bool $needsApproval,
        public readonly string $reason,
        public readonly ?string $model = null,
        public readonly bool $unknown = false,
    ) {
    }

    /** Resposta "nao sei" — erro/cota/invalido. Nunca responde. */
    public static function unknown(string $reason, ?string $model = null): self
    {
        return new self(
            intent: '',
            confidence: 0.0,
            matchedRuleId: null,
            shouldReply: false,
            needsApproval: false,
            reason: $reason,
            model: $model,
            unknown: true,
        );
    }
}
