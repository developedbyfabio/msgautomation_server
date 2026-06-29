<?php

namespace App\Whatsapp\AutoReply;

use App\Models\AutoReplyRule;
use Illuminate\Support\Str;

/**
 * Motor de match das regras (SEM IA). Primeira regra habilitada que casa vence
 * (priority asc, id asc). Sem match (ou texto nulo) -> null -> silencio.
 *
 * Normalizacao nos DOIS lados: fold de acento (Str::ascii) + lowercase + trim +
 * colapso de espacos.
 *
 * 'contains' = PALAVRA INTEIRA (whole-word), multibyte-safe — evita "ola" casar dentro
 * de "escola". Para trocar por substring, mexa SO em containsWholeWord().
 */
class RuleMatcher
{
    public function match(int $accountId, ?int $channelId, ?string $text): ?AutoReplyRule
    {
        if ($text === null) {
            return null;
        }

        $normText = $this->normalize($text);
        if ($normText === '') {
            return null;
        }

        $rules = AutoReplyRule::query()
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
            if ($this->matches($rule->match_type, $normText, $this->normalize($rule->match_value))) {
                return $rule;
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

    private function matches(string $type, string $text, string $value): bool
    {
        if ($value === '') {
            return false;
        }

        return match ($type) {
            'exact' => $text === $value,
            'starts_with' => str_starts_with($text, $value),
            'contains' => $this->containsWholeWord($text, $value),
            default => false,
        };
    }

    private function containsWholeWord(string $text, string $value): bool
    {
        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($value, '/') . '(?![\p{L}\p{N}])/u';

        return (bool) preg_match($pattern, $text);
    }
}
