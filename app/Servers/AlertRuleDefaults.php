<?php

namespace App\Servers;

/**
 * Servidores S2 — padroes sensatos das regras GLOBAIS (server_id NULL),
 * editaveis na tela Alertas. Garantidos de forma LAZY e idempotente
 * (firstOrCreate por metrica): a migration seedou as contas existentes;
 * isto cobre contas futuras sem depender de migration com dado.
 */
class AlertRuleDefaults
{
    /**
     * metric => [limiares/duracoes]. Watchdog: limiares em SEGUNDOS sem reportar.
     * Cadencia (sobrescrivivel na UI): critical_repeat_s = cooldown_s (preserva o
     * S3 — critical re-avisa); warning_repeat_s = null (warning avisa 1 vez).
     */
    public const DEFAULTS = [
        'cpu' => ['warning_threshold' => 85, 'critical_threshold' => 95, 'warning_for_s' => 300, 'critical_for_s' => 120, 'cooldown_s' => 1800, 'warning_repeat_s' => null, 'critical_repeat_s' => 1800],
        'ram' => ['warning_threshold' => 85, 'critical_threshold' => 95, 'warning_for_s' => 300, 'critical_for_s' => 120, 'cooldown_s' => 1800, 'warning_repeat_s' => null, 'critical_repeat_s' => 1800],
        'swap' => ['warning_threshold' => 25, 'critical_threshold' => 50, 'warning_for_s' => 300, 'critical_for_s' => 300, 'cooldown_s' => 1800, 'warning_repeat_s' => null, 'critical_repeat_s' => 1800],
        'disk' => ['warning_threshold' => 85, 'critical_threshold' => 95, 'warning_for_s' => 60, 'critical_for_s' => 60, 'cooldown_s' => 3600, 'warning_repeat_s' => null, 'critical_repeat_s' => 3600],
        'load' => ['warning_threshold' => 1.5, 'critical_threshold' => 2.5, 'warning_for_s' => 300, 'critical_for_s' => 300, 'cooldown_s' => 1800, 'warning_repeat_s' => null, 'critical_repeat_s' => 1800],
        'watchdog' => ['warning_threshold' => 180, 'critical_threshold' => 300, 'warning_for_s' => 0, 'critical_for_s' => 0, 'cooldown_s' => 1800, 'warning_repeat_s' => null, 'critical_repeat_s' => 1800],
    ];

    /** Garante as regras globais da conta (idempotente; nao sobrescreve edicao do dono). */
    public static function ensureFor(int $accountId): void
    {
        foreach (self::DEFAULTS as $metric => $valores) {
            AlertRule::withoutAccountScope()->firstOrCreate(
                ['account_id' => $accountId, 'server_id' => null, 'metric' => $metric],
                $valores + ['enabled' => true],
            );
        }
    }
}
