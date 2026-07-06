<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Fatia 25 — CNPJ com DIGITO VERIFICADOR real (modulo 11, pesos 5..2/9..2 e
 * 6..2/9..2), nao so formato: rejeita sequencias repetidas e DV errado.
 */
class Cnpj implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $cnpj = preg_replace('/\D/', '', (string) $value);

        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            $fail('CNPJ invalido.');

            return;
        }

        $tabelas = [
            [12, [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]],
            [13, [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]],
        ];
        foreach ($tabelas as [$pos, $pesos]) {
            $soma = 0;
            foreach ($pesos as $i => $peso) {
                $soma += (int) $cnpj[$i] * $peso;
            }
            $dv = $soma % 11;
            $dv = $dv < 2 ? 0 : 11 - $dv;
            if ($dv !== (int) $cnpj[$pos]) {
                $fail('CNPJ invalido.');

                return;
            }
        }
    }
}
