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
                if ($this->matches($trigger['type'], $raw, $normText, $this->normalize($trigger['value']), (string) $trigger['value'])) {
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
            if ($this->matches($trigger['type'], $raw, $normText, $this->normalize($trigger['value']), (string) $trigger['value'])) {
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

    private function matches(string $type, string $raw, string $text, string $normValue, string $rawValue): bool
    {
        if ($type === 'regex') {
            return $this->regexMatches($raw, $rawValue);
        }

        if ($normValue === '') {
            return false;
        }

        return match ($type) {
            'exact' => $text === $normValue,
            'starts_with' => str_starts_with($text, $normValue),
            'contains' => $this->containsWholeWord($text, $normValue),
            default => false,
        };
    }

    private function containsWholeWord(string $text, string $value): bool
    {
        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($value, '/') . '(?![\p{L}\p{N}])/u';

        return (bool) preg_match($pattern, $text);
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
