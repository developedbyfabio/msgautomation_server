<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncomingMessage extends Model
{
    protected $fillable = [
        'account_id',
        'channel_id',
        'instance',
        'evolution_message_id',
        'remote_jid',
        'from_me',
        'push_name',
        'type',
        'text',
        'raw_payload',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'from_me' => 'boolean',
            'raw_payload' => 'array',
            'received_at' => 'datetime',
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
}
