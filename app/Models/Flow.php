<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Flow extends Model
{
    protected $fillable = [
        'account_id', 'name', 'enabled', 'scope', 'timeout_seconds', 'invalid_message', 'root_node_id',
    ];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'timeout_seconds' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function triggers(): HasMany
    {
        return $this->hasMany(FlowTrigger::class);
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(FlowNode::class);
    }

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'flow_contacts');
    }

    public function rootNode(): ?FlowNode
    {
        return $this->root_node_id ? FlowNode::find($this->root_node_id) : null;
    }

    /** Gatilhos de entrada no formato do RuleMatcher: ['type','value','precision','fuzzy_level']. */
    public function triggerList(): Collection
    {
        $filhos = $this->relationLoaded('triggers') ? $this->triggers : $this->triggers()->get();

        return $filhos->map(fn ($t) => [
            'type' => $t->match_type,
            'value' => (string) $t->match_value,
            'precision' => $t->precision ?: 'exato',
            'fuzzy_level' => $t->fuzzy_level,
        ]);
    }
}
