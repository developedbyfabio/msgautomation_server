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
        'auto_reply_opt_out', // DEPRECIADO: usar auto_reply_mode
        'auto_reply_mode',    // default | on | off
        'notes',
        'saved',              // true = nomeado/adicionado pelo usuario (S4)
    ];

    protected function casts(): array
    {
        return [
            'auto_reply_opt_out' => 'boolean',
            'saved' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
