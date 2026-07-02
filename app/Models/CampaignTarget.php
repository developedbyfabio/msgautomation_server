<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proativas P-2 — alvo do SNAPSHOT de uma campanha aprovada: um por contato
 * (UNIQUE), com o horario agendado (janela + jitter). Escopado via FK da
 * campanha (padrao das filhas). pending | skipped nesta fase; processing/sent/
 * failed sao do disparo (P-3).
 */
class CampaignTarget extends Model
{
    protected $fillable = [
        'campaign_id',
        'contact_id',
        'status',      // pending | skipped (P-3: processing | sent | failed)
        'skip_reason',
        'scheduled_at',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(ProactiveCampaign::class, 'campaign_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
