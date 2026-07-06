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
        'normalized_text',     // MATCH-1: forma normalizada (observer preenche)
        'normalized_phonetic', // MATCH-2: forma fonetica (caminho tolerante)
    ];

    protected static function booted(): void
    {
        // MATCH-1/2: as formas normalizada E fonetica sao persistidas em TODA
        // escrita (writer, promocao, backfill, teste) — o matcher le as colunas
        // (perf) e o valor nunca fica velho. Regex NAO normaliza (texto cru).
        static::saving(function (self $t) {
            $regex = $t->match_type === 'regex';
            $t->normalized_text = $regex ? null : \App\Whatsapp\TextNormalizer::normalize((string) $t->match_value);
            $t->normalized_phonetic = $regex ? null : \App\Whatsapp\TextNormalizer::phonetic((string) $t->match_value);
        });
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutoReplyRule::class, 'auto_reply_rule_id');
    }
}
