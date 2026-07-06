<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowTrigger extends Model
{
    protected $fillable = ['flow_id', 'match_type', 'match_value', 'precision', 'fuzzy_level', 'normalized_text', 'normalized_phonetic'];

    protected static function booted(): void
    {
        // MATCH-1/2: formas normalizada E fonetica persistidas em TODA escrita
        // (writer, promocao, backfill, teste) — o matcher le as colunas (perf)
        // e o valor nunca fica velho. Regex NAO normaliza (casa o texto cru).
        static::saving(function (self $t) {
            $regex = $t->match_type === 'regex';
            $t->normalized_text = $regex ? null : \App\Whatsapp\TextNormalizer::normalize((string) $t->match_value);
            $t->normalized_phonetic = $regex ? null : \App\Whatsapp\TextNormalizer::phonetic((string) $t->match_value);
        });
    }


    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }
}
