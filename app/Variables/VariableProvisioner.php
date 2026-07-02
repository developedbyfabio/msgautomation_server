<?php

namespace App\Variables;

use App\Models\Variable;

/**
 * V-1 — provisiona as variaveis de SISTEMA de uma conta. Hoje: a {saudacao},
 * com default IDENTICO ao comportamento hard-coded historico (05-11h "Bom dia",
 * 12-17h "Boa tarde", resto "Boa noite", fuso SP) — zero mudanca no deploy.
 * Idempotente. Chamado pela migration (contas existentes) e pelo hook
 * Account::created (contas novas).
 */
class VariableProvisioner
{
    /** Config default da saudacao — ESPELHO exato do match() historico. */
    public const SAUDACAO_DEFAULT = [
        'faixas' => [
            ['inicio' => '05:00', 'fim' => '11:59', 'valor' => 'Bom dia'],
            ['inicio' => '12:00', 'fim' => '17:59', 'valor' => 'Boa tarde'],
        ],
        'valor_padrao' => 'Boa noite',
    ];

    public function ensureSystemVariables(int $accountId): void
    {
        $existe = Variable::withoutAccountScope()
            ->where('account_id', $accountId)
            ->where('name', 'saudacao')
            ->exists();

        if (! $existe) {
            Variable::create([
                'account_id' => $accountId,
                'name' => 'saudacao',
                'type' => 'horario',
                'config' => self::SAUDACAO_DEFAULT,
                'is_system' => true,
                'active' => true,
            ]);
        }
    }
}
