<?php

namespace App\Models;

use App\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proativas P-1 — trilha de CONSENTIMENTO auditavel (LGPD): cada grant/revoke de
 * opt-in proativo, com origem (manual = toggle do painel; palavra = opt-out por
 * palavra no pipeline). NUNCA apagada — e a prova do consentimento e da revogacao.
 */
class ProactiveConsent extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'account_id',
        'contact_id',
        'action', // grant | revoke
        'origin', // manual | palavra
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
