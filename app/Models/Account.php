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
     * +7d). Esta fatia SO grava o marco; o corte no vencimento e da Fatia 26
     * (que adiciona os demais estados junto do gateway). Perfil PF/PJ e trial
     * entram por forceFill no RegisterTenant — fora do $fillable de proposito.
     */
    protected function casts(): array
    {
        return ['trial_ends_at' => 'datetime'];
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
