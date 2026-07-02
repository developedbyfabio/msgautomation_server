<?php

namespace App\Ai;

/**
 * Saida do modo `conhecimento` (DTO). A resposta DEVE ser fundamentada SO no conteudo
 * fornecido da base (`grounded=true` + `sourceIds` validos); qualquer outra coisa e
 * "nao sei" — quem chama silencia/escala, NUNCA envia. `unknown=true` quando o modelo
 * falhou/estourou cota/devolveu algo invalido. `sourceIds` deve ser subconjunto dos
 * ids candidatos (validado por quem chama — nunca confia em id de fora).
 */
final class AiAnswer
{
    /**
     * @param  array<int,int>  $sourceIds  ids das entradas da base que fundamentam a resposta
     */
    public function __construct(
        public readonly string $answer,
        public readonly bool $grounded,
        public readonly float $confidence,
        public readonly bool $needsApproval,
        public readonly array $sourceIds,
        public readonly string $reason,
        public readonly ?string $model = null,
        public readonly bool $unknown = false,
    ) {
    }

    /** Resposta "nao sei" — erro/cota/invalido. Nunca responde. */
    public static function unknown(string $reason, ?string $model = null): self
    {
        return new self(
            answer: '',
            grounded: false,
            confidence: 0.0,
            needsApproval: false,
            sourceIds: [],
            reason: $reason,
            model: $model,
            unknown: true,
        );
    }
}
