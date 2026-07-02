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
        'sent_auto_reply_log_id', // P-3: liga o envio ao log (auditoria)
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

    /**
     * P-3 — revogacao de opt-in (palavra ou manual) PULA o contato em TODAS as
     * campanhas: targets pending viram skipped com motivo. Chamado nos 2 pontos
     * de revoke (pipeline e painel).
     */
    public static function skipAllPendingFor(int $accountId, int $contactId, string $reason): int
    {
        $campanhas = ProactiveCampaign::withoutAccountScope()
            ->where('account_id', $accountId)->pluck('id');

        return self::query()
            ->where('contact_id', $contactId)
            ->where('status', 'pending')
            ->whereIn('campaign_id', $campanhas)
            ->update(['status' => 'skipped', 'skip_reason' => $reason]);
    }
}
