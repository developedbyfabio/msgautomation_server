<?php

namespace App\Enums;

/**
 * Modo de operacao por conta (Fatia 1 — INERTE nesta fatia; ninguem le ainda).
 *  - Personal (default): so regras deterministicas; sem match = silencio (atual).
 *  - Auto: um fluxo padrao atua como catch-all no ramo $rule === null da ingestao (fatia 4).
 * Backed string: a coluna auto_reply_settings.operation_mode guarda 'personal'|'auto'.
 */
enum OperationMode: string
{
    case Personal = 'personal';
    case Auto = 'auto';

    public function label(): string
    {
        return match ($this) {
            self::Personal => 'Pessoal',
            self::Auto => 'Automatico',
        };
    }
}
