<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Fatia 25 — CPF com DIGITO VERIFICADOR real (modulo 11, dois digitos), nao so
 * formato: rejeita sequencias repetidas (111.111.111-11 passa no regex e e
 * invalido) e qualquer DV errado. Espera o valor ja normalizado ou com mascara
 * (a normalizacao aqui e defensiva).
 */
class Cpf implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $cpf = preg_replace('/\D/', '', (string) $value);

        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            $fail('CPF invalido.');

            return;
        }

        foreach ([9, 10] as $len) {
            $soma = 0;
            for ($i = 0; $i < $len; $i++) {
                $soma += (int) $cpf[$i] * (($len + 1) - $i);
            }
            if (((10 * $soma) % 11) % 10 !== (int) $cpf[$len]) {
                $fail('CPF invalido.');

                return;
            }
        }
    }
}
