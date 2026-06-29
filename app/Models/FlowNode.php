<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlowNode extends Model
{
    protected $fillable = ['flow_id', 'parent_node_id', 'kind', 'message', 'ordem'];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(FlowOption::class)->orderBy('ordem')->orderBy('id');
    }

    public function isFinal(): bool
    {
        return $this->kind === 'final';
    }
}
