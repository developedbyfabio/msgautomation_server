<?php

namespace App\Servers;

use App\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Servidores — mensagem configuravel de alerta (rotacao por regra/nivel).
 * level = warning|critical (lista rotacionada nos re-avisos) | resolved (texto
 * unico de resolucao). O texto aceita variaveis: {servidor} {metrica} {valor}
 * {nivel} {particao} (substituidas no envio pelo AlertMessageResolver).
 */
class AlertMessage extends Model
{
    use BelongsToAccount;

    protected $table = 'server_alert_messages';

    public const LEVELS = ['warning', 'critical', 'resolved'];

    protected $fillable = ['account_id', 'rule_id', 'level', 'position', 'text'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'rule_id');
    }
}
