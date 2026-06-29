<?php

namespace App\Whatsapp\AutoReply;

use App\Models\AutoReplyRule;
use Illuminate\Support\Str;

/**
 * Motor de match das regras (SEM IA). Primeira regra habilitada que casa vence
 * (priority asc, id asc). Sem match (ou texto nulo) -> null -> silencio.
 *
 * S7 — multiplos gatilhos por regra (rule_triggers; cai pro legado se nao houver).
 * A regra casa se QUALQUER gatilho dela casa.
 *
 * Tipos:
 *  - exact / starts_with / contains: normalizacao nos dois lados (fold de acento via
 *    Str::ascii + lowercase + trim + colapso de espacos). 'contains' = palavra inteira
 *    (multibyte-safe) — evita "ola" casar dentro de "escola".
 *  - regex (avancado): aplicado no texto ORIGINAL (sem fold), flags 'iu'. PROTEGIDO:
 *    delimitador escapado, try-catch e backtrack_limit reduzido (padrao catastrofico
 *    estoura o limite -> retorna no-match em vez de travar). Padrao invalido -> no-match.
 */
class RuleMatcher
{
    /** Limite de backtracking ao rodar regex de usuario (anti-catastrofe). */
    private const REGEX_BACKTRACK_LIMIT = 100000;

    public function match(int $accountId, ?int $channelId, ?string $text, ?string $remoteJid = null): ?AutoReplyRule
    {
        if ($text === null) {
            return null;
        }

        $raw = trim($text);
        $normText = $this->normalize($text);
        if ($normText === '') {
            return null;
        }

        $rules = AutoReplyRule::query()
            ->with(['triggers', 'responses', 'contacts'])
            ->where('account_id', $accountId)
            ->where('enabled', true)
            ->where(function ($q) use ($channelId) {
                $q->whereNull('channel_id');
                if ($channelId !== null) {
                    $q->orWhere('channel_id', $channelId);
                }
            })
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            // S3: escopo. Regra 'contatos' so entra na avaliacao se o remetente esta na
            // lista; 'global' sempre entra. Depois segue o match normal (e a prioridade).
            if (! $this->scopeEligible($rule, $remoteJid)) {
                continue;
            }

            foreach ($rule->triggerList() as $trigger) {
                if ($this->triggerMatches($trigger, $raw, $normText)) {
                    return $rule;
                }
            }
        }

        return null;
    }

    /** S3 — a regra e elegivel para este remetente? */
    private function scopeEligible(AutoReplyRule $rule, ?string $remoteJid): bool
    {
        if (($rule->scope ?: 'global') === 'global') {
            return true;
        }

        // Escopo 'contatos': precisa saber o remetente e ele tem que estar na lista.
        if ($remoteJid === null) {
            return false;
        }

        $contatos = $rule->relationLoaded('contacts') ? $rule->contacts : $rule->contacts()->get();

        return $contatos->contains(fn ($c) => $c->remote_jid === $remoteJid);
    }

    /**
     * Qual gatilho da regra casa o texto (p/ o testador S4). Retorna o item do
     * triggerList (['type','value','precision','fuzzy_level']) ou null.
     */
    public function firstMatchingTrigger(AutoReplyRule $rule, string $text): ?array
    {
        $raw = trim($text);
        $normText = $this->normalize($text);
        if ($normText === '') {
            return null;
        }

        foreach ($rule->triggerList() as $trigger) {
            if ($this->triggerMatches($trigger, $raw, $normText)) {
                return $trigger;
            }
        }

        return null;
    }

    public function normalize(string $value): string
    {
        $value = Str::ascii($value);          // fold de acento -> ascii
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/\s+/', ' ', $value);

        return trim($value);
    }

    /**
     * Decide se UM gatilho casa o texto. $trigger: ['type','value','precision','fuzzy_level'].
     * precision 'tolerante' (S5) so vale para tipos de texto; regex nunca e fuzzy.
     */
    private function triggerMatches(array $trigger, string $raw, string $normText): bool
    {
        $type = $trigger['type'];

        if ($type === 'regex') {
            return $this->regexMatches($raw, (string) $trigger['value']);
        }

        $normValue = $this->normalize((string) $trigger['value']);
        if ($normValue === '') {
            return false;
        }

        $tolerante = ($trigger['precision'] ?? 'exato') === 'tolerante';
        if (! $tolerante) {
            return match ($type) {
                'exact' => $normText === $normValue,
                'starts_with' => str_starts_with($normText, $normValue),
                'contains' => $this->containsWholeWord($normText, $normValue),
                default => false,
            };
        }

        return $this->fuzzyMatches($type, $normText, $normValue, (string) ($trigger['fuzzy_level'] ?? 'media'));
    }

    private function containsWholeWord(string $text, string $value): bool
    {
        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($value, '/') . '(?![\p{L}\p{N}])/u';

        return (bool) preg_match($pattern, $text);
    }

    // ---- S5: match tolerante (fuzzy), por token, whole-word -----------------

    private function tokens(string $value): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Match tolerante por TOKEN (Levenshtein, whole-word). Multi-token exige a
     * sequencia consecutiva (mantem o whole-word). Texto ja normalizado (ascii
     * lowercase) -> levenshtein byte-safe.
     */
    private function fuzzyMatches(string $type, string $normText, string $normValue, string $level): bool
    {
        $alvo = $this->tokens($normValue);
        $msg = $this->tokens($normText);
        $n = count($alvo);
        if ($n === 0 || count($msg) < $n) {
            return false;
        }

        if ($type === 'exact') {
            return count($msg) === $n && $this->tokenSeqMatches($msg, 0, $alvo, $level);
        }

        if ($type === 'starts_with') {
            return $this->tokenSeqMatches($msg, 0, $alvo, $level);
        }

        // contains: procura a sequencia consecutiva em qualquer posicao.
        for ($i = 0; $i + $n <= count($msg); $i++) {
            if ($this->tokenSeqMatches($msg, $i, $alvo, $level)) {
                return true;
            }
        }

        return false;
    }

    /** Os tokens-alvo casam (cada um dentro da folga) a partir de $offset no $msg? */
    private function tokenSeqMatches(array $msg, int $offset, array $alvo, string $level): bool
    {
        foreach ($alvo as $k => $t) {
            if (! $this->fuzzyTokenEqual($msg[$offset + $k], (string) $t, $level)) {
                return false;
            }
        }

        return true;
    }

    private function fuzzyTokenEqual(string $msgToken, string $trigToken, string $level): bool
    {
        $len = strlen($trigToken);

        // Guarda-corpo: token curto (< 4) NUNCA tolera erro -> so exato.
        if ($len < 4) {
            return $msgToken === $trigToken;
        }

        $allowed = $this->allowedDistance($len, $level);
        if ($allowed === 0) {
            return $msgToken === $trigToken;
        }

        // Poda barata: diferenca de tamanho ja maior que a folga -> nao casa.
        if (abs(strlen($msgToken) - $len) > $allowed) {
            return false;
        }

        return levenshtein($msgToken, $trigToken) <= $allowed;
    }

    /**
     * Folga (distancia maxima de edicao) que ESCALA com o tamanho do token, com teto
     * baixo por nivel. Conservador de proposito (anti falso-positivo):
     *   baixa  -> ~len/6 (teto 1)   media -> ~len/4 (teto 2)   alta -> ~len/3 (teto 2)
     */
    private function allowedDistance(int $len, string $level): int
    {
        [$divisor, $cap] = match ($level) {
            'baixa' => [6, 1],
            'alta' => [3, 2],
            default => [4, 2], // media
        };

        return min($cap, intdiv($len, $divisor));
    }

    /**
     * Regex de usuario, com protecao. Retorna false em padrao invalido, erro de PCRE
     * ou estouro de backtracking (padrao catastrofico) — nunca lanca nem trava.
     */
    public function regexMatches(string $text, string $pattern): bool
    {
        $compiled = self::compileRegex($pattern);
        if ($compiled === null) {
            return false;
        }

        $anterior = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', (string) self::REGEX_BACKTRACK_LIMIT);

        try {
            $r = @preg_match($compiled, $text);
        } catch (\Throwable) {
            $r = false;
        } finally {
            ini_set('pcre.backtrack_limit', (string) $anterior);
        }

        // false = erro/estouro; trata como no-match (seguro).
        return $r === 1;
    }

    /**
     * Monta o padrao final (delimitador # escapado, flags i+u) e valida que compila.
     * Retorna o padrao pronto ou null se invalido. Usado tambem na validacao da UI.
     */
    public static function compileRegex(string $pattern): ?string
    {
        if ($pattern === '') {
            return null;
        }

        $compiled = '#' . str_replace('#', '\\#', $pattern) . '#iu';

        if (@preg_match($compiled, '') === false) {
            return null;
        }

        return $compiled;
    }

    /** Validacao de padrao para a UI (true = padrao utilizavel). */
    public static function isValidRegex(string $pattern): bool
    {
        return self::compileRegex($pattern) !== null;
    }
}
