<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowTrigger extends Model
{
    protected $fillable = ['flow_id', 'match_type', 'match_value', 'precision', 'fuzzy_level'];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }
}
