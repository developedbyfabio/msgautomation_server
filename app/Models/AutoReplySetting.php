<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutoReplySetting extends Model
{
    protected $fillable = [
        'account_id',
        'enabled',
        'reply_policy',
        'window_start',
        'window_end',
        'min_interval_seconds',
        'per_minute_cap',
        'per_day_cap',
        'contact_rate_seconds',
        'delay_min_seconds',
        'delay_max_seconds',
        'skip_groups',
        'warmup_enabled',
        'window_enabled',
        'min_interval_enabled',
        'per_minute_enabled',
        'per_day_enabled',
        'contact_rate_enabled',
        // Camada 3 (IA) — configuracao global.
        'ai_enabled',
        'ai_confidence_threshold',
        'ai_approval_topics',
    ];

    /** Temas que SEMPRE exigem aprovacao (a IA nunca responde direto). */
    public const AI_APPROVAL_TOPICS = ['pagamento', 'dados_bancarios', 'compromissos', 'conteudo_high'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'skip_groups' => 'boolean',
            'warmup_enabled' => 'boolean',
            'window_enabled' => 'boolean',
            'min_interval_enabled' => 'boolean',
            'per_minute_enabled' => 'boolean',
            'per_day_enabled' => 'boolean',
            'contact_rate_enabled' => 'boolean',
            'min_interval_seconds' => 'integer',
            'per_minute_cap' => 'integer',
            'per_day_cap' => 'integer',
            'contact_rate_seconds' => 'integer',
            'delay_min_seconds' => 'integer',
            'delay_max_seconds' => 'integer',
            'ai_enabled' => 'boolean',
            'ai_confidence_threshold' => 'float',
            'ai_approval_topics' => 'array',
        ];
    }

    /**
     * Temas de aprovacao efetivos. NULL (nunca configurado) = TODOS ligados
     * (conservador — o Fabio aprovou "sempre exige aprovacao" nos 4 temas).
     *
     * @return array<int,string>
     */
    public function aiApprovalTopics(): array
    {
        $t = $this->ai_approval_topics;

        return is_array($t) ? array_values($t) : self::AI_APPROVAL_TOPICS;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
