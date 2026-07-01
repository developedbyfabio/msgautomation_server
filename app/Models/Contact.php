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
        'ai_enabled',         // IA por contato (Camada 3). Default false.
        'ai_mode',            // rules_only | intencao | conhecimento | aprovacao
    ];

    protected function casts(): array
    {
        return [
            'auto_reply_opt_out' => 'boolean',
            'saved' => 'boolean',
            'ai_enabled' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
