<?php

namespace App\Channels\CloudApi;

/**
 * CH-2 Parte B — canonicalizacao do 9o digito BR num PONTO UNICO (entrada e
 * saida usam a MESMA regra; divergir aqui ja quebrou envio real: a Meta
 * entregou o wa_id sem o 9, o envio ecoou a forma sem 9 e a allowlist do
 * numero de teste — cadastrada com 9 — recusou com 400/131030).
 *
 * Regra: celular BR e DDI 55 + DDD (2) + 9 digitos comecando com 9; a Cloud
 * API pode entregar o wa_id SEM esse 9 (comportamento documentado BR/MX).
 * O primeiro digito do numero-base [6-9] distingue celular de fixo (fixo
 * comeca 2-5) — fixo NUNCA ganha 9.
 */
final class BrWaId
{
    /** Variante COM o 9 (55 DD [6-9]XXXXXXX -> 55 DD 9 [6-9]XXXXXXX), ou null se nao for celular BR sem 9. */
    public static function comNonoDigito(string $digits): ?string
    {
        return preg_match('/^55(\d{2})([6-9]\d{7})$/', $digits, $m)
            ? '55' . $m[1] . '9' . $m[2]
            : null;
    }

    /** Variante SEM o 9 (55 DD 9 [6-9]XXXXXXX -> 55 DD [6-9]XXXXXXX), ou null se nao for celular BR com 9. */
    public static function semNonoDigito(string $digits): ?string
    {
        return preg_match('/^55(\d{2})9([6-9]\d{7})$/', $digits, $m)
            ? '55' . $m[1] . $m[2]
            : null;
    }

    /** Formato que a Meta espera no ENVIO: celular BR sempre COM o 9. */
    public static function paraEnvio(string $digits): string
    {
        return self::comNonoDigito($digits) ?? $digits;
    }
}
