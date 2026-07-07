<?php

namespace App\Servers;

use App\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Servidores S3 — destinatario de alerta (roteamento). Escopo por conta (A1).
 * Alvo: server_id especifico OU grupo (quando server_id NULL) OU todos
 * (ambos NULL). min_level filtra por severidade: 'warning' recebe warning e
 * critical; 'critical' recebe so critical.
 */
class AlertContact extends Model
{
    use BelongsToAccount;

    protected $table = 'server_alert_contacts';

    protected $fillable = [
        'account_id', 'server_id', 'grupo', 'name', 'phone', 'email', 'min_level', 'enabled',
    ];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** Este contato deve receber um incidente deste nivel, neste servidor/grupo? */
    public function matches(string $level, Server $server): bool
    {
        if (! $this->enabled) {
            return false;
        }
        // Severidade: critical passa por qualquer min_level; warning so se min_level=warning.
        if ($this->min_level === 'critical' && $level !== 'critical') {
            return false;
        }
        // Alvo: servidor especifico > grupo > todos.
        if ($this->server_id !== null) {
            return $this->server_id === $server->id;
        }
        if ($this->grupo !== null) {
            return $this->grupo === $server->grupo;
        }

        return true; // sem alvo = todos os servidores da conta
    }
}
