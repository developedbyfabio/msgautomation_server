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

        // 5b. MATCH-2 (fatia 19) — squeeze de repeticao EXPRESSIVA: runs de 3+
        //     letras iguais colapsam pra 1 ("oiii"->"oi", "simmm"->"sim").
        //     Seguro em TODOS os modos: nenhuma palavra pt-BR legitima tem 3
        //     letras repetidas, e a mesma funcao roda nos DOIS lados (gatilho e
        //     mensagem) — colapso simetrico. Runs de 2 ("oii", "arroz") NAO
        //     colapsam aqui (contrato dos modos estritos preservado).
        $texto = preg_replace('/([a-z0-9])\1{2,}/', '$1', $texto) ?? '';

        // 6. espacos.
        return trim(preg_replace('/\s+/', ' ', $texto) ?? '');
    }

    /**
     * MATCH-2 (fatia 19) — camada FONETICA, APENAS pro caminho tolerante/fuzzy
     * (os modos estritos usam SO o normalize acima). Colapsos CONSERVADORES de
     * pt-BR aplicados aos DOIS lados depois da base — regra de ouro: na duvida,
     * fica de fora (colapso a menos = falso negativo recuperavel pelo nivel;
     * colapso a mais = falso positivo em producao).
     *
     * Tabela (ordem importa — pensada pra idempotencia: nenhum passo cria
     * padrao que um passo anterior transformaria):
     *  0. ç -> s ANTES da base (Str::ascii dobraria ç->c e perderia o som /s/:
     *     "preço"->"preso", casando "presso"/"presu") — desvio registrado;
     *  1. digrafos: ch->x, sh->x, ph->f ("chave"≈"xave");
     *  2. h INICIAL de palavra removido ("horario"≈"orario"; h no meio fica —
     *     "senha" nao vira "sena": nh/lh ficaram FORA por conservadorismo);
     *  3. k->c, w->v, y->i, z->s ("meza"≈"mesa");
     *  4. c antes de e/i -> s ("cedo"≈"sedo") — DEPOIS do k->c;
     *  5. letras duplicadas -> 1 (rr/ss/ll/...): "presso"->"preso".
     *
     * EXCLUIDOS deliberadamente (regra de ouro): qu->q encurtaria "que" pra 2
     * letras e MATAVA a transposicao "qeu"≈"que" (e "qero"≈"quero" ja casa por
     * distancia 1); nh/lh/gu colapsos a mais = falso positivo em producao.
     */
    public static function phonetic(string $texto): string
    {
        $t = self::normalize(str_replace(['ç', 'Ç'], 's', $texto));

        $t = str_replace(['ch', 'sh', 'ph'], ['x', 'x', 'f'], $t);
        $t = preg_replace('/\bh/', '', $t) ?? '';
        $t = strtr($t, ['k' => 'c', 'w' => 'v', 'y' => 'i', 'z' => 's']);
        $t = preg_replace('/c(?=[ei])/', 's', $t) ?? '';
        $t = preg_replace('/([a-z])\1+/', '$1', $t) ?? '';

        return trim(preg_replace('/\s+/', ' ', $t) ?? '');
    }
}
