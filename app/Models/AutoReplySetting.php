<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutoReplySetting extends Model
{
    protected $fillable = [
        'account_id',
        'enabled',
        'window_start',
        'window_end',
        'min_interval_seconds',
        'per_minute_cap',
        'per_day_cap',
        'contact_rate_seconds',
        'delay_min_seconds',
        'delay_max_seconds',
        'skip_groups',
        'warmup_enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'skip_groups' => 'boolean',
            'warmup_enabled' => 'boolean',
            'min_interval_seconds' => 'integer',
            'per_minute_cap' => 'integer',
            'per_day_cap' => 'integer',
            'contact_rate_seconds' => 'integer',
            'delay_min_seconds' => 'integer',
            'delay_max_seconds' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
