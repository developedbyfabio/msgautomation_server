<?php

namespace App\Whatsapp\Secrets;

use RuntimeException;

/**
 * Lancada quando uma resposta referencia {senha:nome} que NAO existe no cofre.
 * A mensagem so cita o NOME (nunca um valor) — falha controlada e redigida.
 */
class SecretMissingException extends RuntimeException
{
    public function __construct(public readonly string $nome)
    {
        parent::__construct("Senha referenciada nao encontrada no cofre: [{$nome}].");
    }
}
