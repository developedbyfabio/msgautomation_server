<?php

namespace App\Servers;

use App\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'warning_for_s', 'critical_for_s', 'resolve_for_s', 'cooldown_s',
        'warning_repeat_s', 'critical_repeat_s', 'enabled',
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
            'warning_repeat_s' => 'integer',
            'critical_repeat_s' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    /** Intervalo de RE-AVISO (s) para o nivel. null/0 = avisar 1 vez (nao repete). */
    public function repeatSecondsFor(string $level): ?int
    {
        $v = $level === 'critical' ? $this->critical_repeat_s : $this->warning_repeat_s;

        return ($v !== null && $v > 0) ? (int) $v : null;
    }

    /** Mensagens (rotacao) de um nivel/kind, em ordem. level: warning|critical|resolved. */
    public function messages(): HasMany
    {
        return $this->hasMany(AlertMessage::class, 'rule_id')->orderBy('position');
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
