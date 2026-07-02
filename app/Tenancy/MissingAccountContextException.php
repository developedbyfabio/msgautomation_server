<?php

namespace App\Tenancy;

use RuntimeException;

/**
 * MT-0 — lancada quando uma query de dominio roda SEM contexto de conta definido
 * (e sem o fallback de conta unica da fase 1). FALHAR ALTO e deliberado: o pior
 * bug possivel do multi-tenant e a query silenciosa que retorna dados de outra
 * conta (ou de todas). Quem precisa cruzar contas de proposito usa o bypass
 * NOMEADO: Model::withoutAccountScope().
 */
class MissingAccountContextException extends RuntimeException
{
    public function __construct(string $message = 'Contexto de conta nao definido. Defina com AccountContext::set() (jobs/comandos) ou use o bypass explicito Model::withoutAccountScope() para consultas cross-account legitimas.')
    {
        parent::__construct($message);
    }
}
