<?php

namespace App\Models;

use App\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Channel extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'account_id',
        'instance',
        'webhook_token', // MT-0: token do webhook por canal (unico)
        'status',
        'remote_jid',
        'connected_at',
        'last_event_at',
    ];

    protected function casts(): array
    {
        return [
            'connected_at' => 'datetime',
            'last_event_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function incomingMessages(): HasMany
    {
        return $this->hasMany(IncomingMessage::class);
    }
}
