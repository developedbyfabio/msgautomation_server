<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RuleTrigger extends Model
{
    protected $fillable = [
        'auto_reply_rule_id',
        'match_type',  // exact | contains | starts_with | regex
        'match_value',
        'precision',   // exato | tolerante  (S5)
        'fuzzy_level', // baixa | media | alta (quando tolerante)
        'normalized_text', // MATCH-1: forma normalizada (observer preenche)
    ];

    protected static function booted(): void
    {
        // MATCH-1: a forma normalizada e persistida em TODA escrita (writer,
        // promocao, backfill, teste) — o matcher le a coluna (perf) e o valor
        // nunca fica velho. Regex NAO normaliza (casa contra o texto cru).
        static::saving(function (self $t) {
            $t->normalized_text = $t->match_type === 'regex'
                ? null
                : \App\Whatsapp\TextNormalizer::normalize((string) $t->match_value);
        });
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutoReplyRule::class, 'auto_reply_rule_id');
    }
}
