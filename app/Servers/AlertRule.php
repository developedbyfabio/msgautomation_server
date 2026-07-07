<?php

namespace App\Servers;

use App\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Servidores S2 — regra de alerta. server_id NULL = padrao GLOBAL da conta;
 * preenchido = sobrescrita de um servidor (precedencia: especifica > global,
 * inclusive quando a especifica esta enabled=false — silencia a metrica).
 *
 * Limiar por metrica: % para cpu/ram/swap/disk; load1 POR NUCLEO para load
 * (sem cpu_count no payload: comparacao ABSOLUTA — limitacao registrada);
 * SEGUNDOS sem reportar para watchdog. for_duration por NIVEL (warning/
 * critical) — histerese sobre o buffer efemero.
 */
class AlertRule extends Model
{
    use BelongsToAccount;

    protected $table = 'server_alert_rules';

    public const METRICS = ['cpu', 'ram', 'swap', 'disk', 'load', 'watchdog'];

    public const LABELS = [
        'cpu' => 'CPU',
        'ram' => 'RAM',
        'swap' => 'Swap',
        'disk' => 'Disco',
        'load' => 'Load por nucleo',
        'watchdog' => 'Sem reportar (watchdog)',
    ];

    protected $fillable = [
        'account_id', 'server_id', 'metric', 'mount',
        'warning_threshold', 'critical_threshold',
        'warning_for_s', 'critical_for_s', 'resolve_for_s', 'cooldown_s', 'enabled',
    ];

    protected function casts(): array
    {
        return [
            'warning_threshold' => 'float',
            'critical_threshold' => 'float',
            'warning_for_s' => 'integer',
            'critical_for_s' => 'integer',
            'resolve_for_s' => 'integer',
            'cooldown_s' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function isGlobal(): bool
    {
        return $this->server_id === null;
    }
}
