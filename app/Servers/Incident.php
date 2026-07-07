<?php

namespace App\Servers;

use App\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Servidores — incidente (estado DURAVEL no MySQL; a fonte de verdade — flush
 * do Redis nao ressuscita resolvido nem reabre aberto). Ciclo SIMPLES:
 * firing -> resolved. NAO existe "reconhecer" (ack): o incidente vive como
 * firing (aberto) e re-avisa pela cadencia ate normalizar, quando resolve e
 * avisa 1 vez. (As colunas acknowledged_* permanecem no schema por
 * compatibilidade, mas nao sao mais usadas — o app nunca as escreve.)
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

    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'account_id', 'server_id', 'rule_id', 'metric', 'mount',
        'level', 'status', 'open_key', 'value_at_fire', 'detail',
        'started_at', 'resolved_at',
        'notified_firing_at', 'notified_resolved_at', 'notified_level', 'last_notified_at', 'notify_count',
    ];

    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'value_at_fire' => 'float',
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
            'notified_firing_at' => 'datetime',
            'notified_resolved_at' => 'datetime',
            'last_notified_at' => 'datetime',
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
