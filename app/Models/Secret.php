<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cofre de senhas. value_encrypted guarda o valor CIFRADO (cifra dedicada via
 * SecretVault/SecretCipher). NUNCA exponha value_encrypted decifrado em massa nem
 * o inclua em arrays serializados/logs. $hidden protege contra vazamento acidental.
 */
class Secret extends Model
{
    protected $fillable = [
        'account_id',
        'nome',
        'value_encrypted',
        'categoria',
        'notes',
    ];

    protected $hidden = [
        'value_encrypted',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
