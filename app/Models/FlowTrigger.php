<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowTrigger extends Model
{
    protected $fillable = ['flow_id', 'match_type', 'match_value', 'precision', 'fuzzy_level', 'normalized_text'];

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


    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }
}
