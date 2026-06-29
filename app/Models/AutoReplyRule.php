<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class AutoReplyRule extends Model
{
    protected $fillable = [
        'account_id',
        'channel_id',
        'match_type',
        'match_value',
        'response_text',
        'enabled',
        'priority',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'priority' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function triggers(): HasMany
    {
        return $this->hasMany(RuleTrigger::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(RuleResponse::class);
    }

    /**
     * Gatilhos efetivos: as filhas (rule_triggers) ou, se nao houver, o legado
     * (match_type/match_value) como gatilho unico. Cada item: ['type','value'].
     */
    public function triggerList(): Collection
    {
        $filhos = $this->relationLoaded('triggers') ? $this->triggers : $this->triggers()->get();

        if ($filhos->isNotEmpty()) {
            return $filhos->map(fn ($t) => ['type' => $t->match_type, 'value' => (string) $t->match_value]);
        }

        if ((string) $this->match_value !== '') {
            return collect([['type' => $this->match_type ?: 'contains', 'value' => (string) $this->match_value]]);
        }

        return collect();
    }

    /** Respostas efetivas: as filhas (rule_responses) ou o legado (response_text). */
    public function responseList(): Collection
    {
        $filhos = $this->relationLoaded('responses') ? $this->responses : $this->responses()->get();

        if ($filhos->isNotEmpty()) {
            return $filhos->pluck('response_text')->filter(fn ($t) => (string) $t !== '')->values();
        }

        return (string) $this->response_text !== '' ? collect([$this->response_text]) : collect();
    }
}
