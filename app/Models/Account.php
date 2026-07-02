<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Account extends Model
{
    protected $fillable = ['name'];

    /**
     * Kanban K-1: toda conta NOVA nasce com o board default (colunas D4 + regras
     * minimas). Contas existentes foram provisionadas pela migration 000026.
     */
    protected static function booted(): void
    {
        static::created(function (Account $account) {
            app(\App\Kanban\BoardProvisioner::class)->ensureDefaultBoard((int) $account->id);
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
