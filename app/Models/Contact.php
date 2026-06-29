<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contact extends Model
{
    protected $fillable = [
        'account_id',
        'remote_jid',
        'push_name',
        'auto_reply_opt_out',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'auto_reply_opt_out' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
