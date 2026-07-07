<?php

namespace App\Servers;

use App\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Servidores S2 — incidente (estado DURAVEL no MySQL; a fonte de verdade —
 * flush do Redis nao ressuscita resolvido nem reabre aberto). Maquina de
 * estado: firing -> acknowledged (ack do dono; segue aberto) -> resolved.
 *
 * open_key = "{server}:{metric}[:{mount}]" enquanto aberto, NULL apos o
 * resolve: o unique garante NO BANCO um ativo por (servidor, metrica/particao)
 * e faz a avaliacao ser idempotente (corrida -> UniqueConstraintViolation ->
 * no-op).
 */
class Incident extends Model
{
    use BelongsToAccount;

    protected $table = 'server_incidents';

    public const STATUS_FIRING = 'firing';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'account_id', 'server_id', 'rule_id', 'metric', 'mount',
        'level', 'status', 'open_key', 'value_at_fire', 'detail',
        'started_at', 'acknowledged_at', 'acknowledged_by', 'resolved_at',
        'notified_firing_at', 'notified_resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'value_at_fire' => 'float',
            'started_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'notified_firing_at' => 'datetime',
            'notified_resolved_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function isOpen(): bool
    {
        return $this->status !== self::STATUS_RESOLVED;
    }

    /** Chave de unicidade do incidente ABERTO. */
    public static function openKey(int $serverId, string $metric, ?string $mount = null): string
    {
        return $serverId.':'.$metric.($mount !== null ? ':'.$mount : '');
    }
}
