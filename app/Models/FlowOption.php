<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowOption extends Model
{
    protected $fillable = ['flow_node_id', 'input', 'label', 'next_node_id', 'ordem'];

    public function node(): BelongsTo
    {
        return $this->belongsTo(FlowNode::class, 'flow_node_id');
    }
}
