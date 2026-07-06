<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Account extends Model
{
    /** MT-1 — usuarios vinculados (pivot account_user; role owner|operador). */
    public function users(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(\App\Models\User::class, 'account_user')
            ->withPivot('role')->withTimestamps();
    }

    protected $fillable = ['name'];

    /**
     * Fatia 25 — estado de assinatura. 'active' = contas legadas/criadas pelo
     * admin (default da coluna); 'trial' = cadastro publico (trial_ends_at =
     * +7d). Fatia 26 completou a maquina: trial -> active -> overdue ->
     * suspended -> canceled, dirigida pelos webhooks de COBRANCA do Asaas
     * (App\Billing\BillingState) + sweep diario do corte de trial. Perfil
     * PF/PJ, trial e ids do Asaas entram por forceFill — fora do $fillable.
     */
    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'overdue_since' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    /**
     * Fatia 26 — gate de OPERACAO: conta suspensa/cancelada NAO opera (bot nao
     * responde; painel so na billing). NADA e apagado — reversivel: pagamento
     * confirmado no webhook volta pra 'active' e tudo religa.
     */
    public function podeOperar(): bool
    {
        return ! in_array($this->subscription_status, ['suspended', 'canceled'], true);
    }

    /**
     * Kanban K-1: toda conta NOVA nasce com o board default (colunas D4 + regras
     * minimas). Contas existentes foram provisionadas pela migration 000026.
     */
    protected static function booted(): void
    {
        static::created(function (Account $account) {
            app(\App\Kanban\BoardProvisioner::class)->ensureDefaultBoard((int) $account->id);
            // V-1: variaveis de sistema ({saudacao} com default identico ao historico).
            app(\App\Variables\VariableProvisioner::class)->ensureSystemVariables((int) $account->id);
        });
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    public function incomingMessages(): HasMany
    {
        return $this->hasMany(IncomingMessage::class);
    }

    public function autoReplySetting(): HasOne
    {
        return $this->hasOne(AutoReplySetting::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function autoReplyLogs(): HasMany
    {
        return $this->hasMany(AutoReplyLog::class);
    }

    public function autoReplyRules(): HasMany
    {
        return $this->hasMany(AutoReplyRule::class);
    }
}
