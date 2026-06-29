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
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutoReplyRule::class, 'auto_reply_rule_id');
    }
}
