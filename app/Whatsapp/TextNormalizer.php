<?php

namespace App\Whatsapp;

use Illuminate\Support\Str;

/**
 * MATCH-1 — o normalizador UNICO de texto pra CASAMENTO. Toda comparacao de
 * gatilho/mensagem/opcao/palavra passa por AQUI — e proibido normalizar
 * diferente em pontos diferentes (a licao do "Que horas são?" vs "... são ?").
 *
 * Especificacao (nesta ordem):
 *  1. NFKC (quando ext-intl presente): unifica formas unicode compostas/
 *     compatibilidade (full-width, ligaduras, keycaps);
 *  2. invisiveis: U+00A0 (nbsp) vira espaco; zero-width (200B/200C/200D/FEFF)
 *     e variation selector (FE0F) somem;
 *  3. caixa baixa (multibyte);
 *  4. fold de acentos/diacriticos (sao=são, voce=você) via Str::ascii;
 *  5. pontuacao/simbolos/emoji REMOVIDOS — nas bordas E no meio, sem deixar
 *     espaco ("wi-fi"="wifi", "horas?!"="horas", "1."="1", "1️⃣"="1");
 *  6. colapso de espacos multiplos + trim.
 *
 * REGEX NAO passa por aqui: padrao do autor casa contra o texto CRU (quem
 * escolhe regex controla a precisao).
 */
final class TextNormalizer
{
    public static function normalize(string $texto): string
    {
        // 1. NFKC (best-effort: sem ext-intl, os passos seguintes ainda cobrem).
        if (class_exists(\Normalizer::class)) {
            $texto = \Normalizer::normalize($texto, \Normalizer::FORM_KC) ?: $texto;
        }

        // 2. invisiveis.
        $texto = str_replace(
            ["\u{00A0}", "\u{202F}", "\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}", "\u{FE0F}"],
            [' ', ' ', '', '', '', '', ''],
            $texto,
        );

        // 3 + 4. caixa e acentos.
        $texto = Str::ascii(mb_strtolower($texto, 'UTF-8'));

        // 5. so letras/numeros/espaco sobrevivem (pontuacao/simbolo/emoji fora,
        //    SEM virar espaco — "wi-fi" -> "wifi").
        $texto = preg_replace('/[^a-z0-9\s]+/', '', $texto) ?? '';

        // 6. espacos.
        return trim(preg_replace('/\s+/', ' ', $texto) ?? '');
    }
}
