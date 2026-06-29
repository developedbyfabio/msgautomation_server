<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowSession extends Model
{
    protected $fillable = [
        'account_id', 'flow_id', 'remote_jid', 'current_node_id', 'status',
        'started_at', 'last_activity_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'last_activity_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function currentNode(): ?FlowNode
    {
        return $this->current_node_id ? FlowNode::find($this->current_node_id) : null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && now()->greaterThan($this->expires_at);
    }
}
