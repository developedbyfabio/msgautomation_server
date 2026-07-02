<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pendencia de aprovacao humana (Camada 3 Fatia 3). Nasce quando a IA ESCALA
 * (nunca de resposta automatica). NADA e enviado sem clique humano no /revisao.
 *
 * suggested_response guarda a sugestao com placeholders INTACTOS ({senha:nome},
 * {nome}, ...) — valor de segredo NUNCA persistido aqui; na UI aparece MASCARADO;
 * resolucao so no envio (Sender). Depois de decidida (approved/edited/rejected/
 * expired) a pendencia TRAVA — sem re-envio.
 */
class PendingApproval extends Model
{
    public const STATUSES = ['pending', 'approved', 'edited', 'rejected', 'expired'];

    protected $fillable = [
        'account_id',
        'contact_id',
        'incoming_message_id',
        'ai_decision_id',
        'remote_jid',
        'suggested_response',
        'origin',     // regra | base | ia
        'reason',
        'intent',
        'confidence',
        'status',
        'decided_at',
        'sent_auto_reply_log_id',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'decided_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function incomingMessage(): BelongsTo
    {
        return $this->belongsTo(IncomingMessage::class);
    }

    public function aiDecision(): BelongsTo
    {
        return $this->belongsTo(AiDecision::class);
    }

    public function sentLog(): BelongsTo
    {
        return $this->belongsTo(AutoReplyLog::class, 'sent_auto_reply_log_id');
    }

    /** Ainda acionavel? (pendente e dentro da validade) */
    public function isActionable(): bool
    {
        return $this->status === 'pending' && ! $this->isStale();
    }

    /** Mais velha que a validade configurada (mesmo que ainda nao marcada expired). */
    public function isStale(): bool
    {
        $dias = (int) config('ai.approval_expire_days', 7);

        return $dias > 0 && $this->created_at !== null
            && $this->created_at->lt(now()->subDays($dias));
    }

    /**
     * Expiracao leve: marca 'expired' as pendencias velhas (nada e enviado).
     * Chamada no mount do /revisao (lazy) e pelo comando ai:expire-approvals
     * (schedule). Barato: um UPDATE com WHERE indexado. Retorna quantas expiraram.
     */
    public static function expireStale(?int $accountId = null): int
    {
        $dias = (int) config('ai.approval_expire_days', 7);
        if ($dias <= 0) {
            return 0;
        }

        return self::query()
            ->when($accountId !== null, fn ($q) => $q->where('account_id', $accountId))
            ->where('status', 'pending')
            ->where('created_at', '<', now()->subDays($dias))
            ->update(['status' => 'expired', 'decided_at' => now()]);
    }
}
