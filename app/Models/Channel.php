<?php

namespace App\Models;

use App\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Channel extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'account_id',
        'instance',
        'provider',      // CH-1: 'evolution' | 'cloud_api' (ProviderRegistry resolve)
        'credentials',   // CH-1: credenciais POR CANAL, cifradas (fallback env ate MT-2)
        'webhook_token', // MT-0: token do webhook por canal (unico)
        'status',
        'remote_jid',
        'connected_at',
        'last_event_at',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array', // nunca em claro no banco
            'connected_at' => 'datetime',
            'last_event_at' => 'datetime',
        ];
    }

    /**
     * CH-1 — o canal "da conta" pra envios que NAO nascem de um incoming (proativa,
     * comando manual): explicito e nomeado. Semantica identica a escolha historica
     * (oldest id). Multi-canal por conta (MT-2) escolhera por capacidade.
     */
    public static function defaultFor(int $accountId): ?self
    {
        return static::withoutAccountScope()
            ->where('account_id', $accountId)
            ->oldest('id')
            ->first();
    }

    /**
     * Prompt 24b — Callback URL do webhook Cloud desta conta (base publica da config
     * + token do canal). Fonte unica pro comando e pra UI. NAO usa route('webhook.cloud')
     * de proposito: o webhook Cloud vive num subdominio proprio (!= APP_URL do painel).
     */
    public function cloudCallbackUrl(): string
    {
        return rtrim((string) config('services.cloud_api.webhook_base'), '/')
            . '/webhook/cloud/' . $this->webhook_token;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function incomingMessages(): HasMany
    {
        return $this->hasMany(IncomingMessage::class);
    }
}
