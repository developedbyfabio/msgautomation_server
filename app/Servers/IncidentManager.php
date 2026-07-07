<?php

namespace App\Servers;

use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Servidores — maquina de estado do incidente. Ciclo SIMPLES (sem "reconhecer"):
 * firing -> resolved. Invariantes:
 *
 *  - UM incidente ativo por (servidor, metrica[, particao]): open_key unique
 *    decide corridas no banco (create duplicado -> catch -> no-op).
 *  - UMA acao de notificacao por transicao (firing/escalated/resolved) — o
 *    notifier usa ref idempotente; rodar a avaliacao 2x nao duplica nada.
 *  - o incidente vive como firing (aberto) e re-avisa pela cadencia (repeat_s)
 *    ate normalizar; resolve fecha, avisa 1 vez e libera o open_key.
 *  - Escalada warning -> critical atualiza o MESMO incidente (nunca abre um
 *    segundo) e avisa a mudanca; nunca ha downgrade critical -> warning.
 */
class IncidentManager
{
    public function __construct(private AlertNotifier $notifier) {}

    /** Incidente ABERTO (firing) do servidor+metrica[+mount], se houver. */
    public function open(int $serverId, string $metric, ?string $mount = null): ?Incident
    {
        return Incident::withoutAccountScope()
            ->where('open_key', Incident::openKey($serverId, $metric, $mount))
            ->first();
    }

    /**
     * Garante o estado-alvo "condicao violada no nivel $level": abre incidente
     * se nao ha um aberto; escala warning->critical se preciso; no-op se ja
     * esta no nivel (sem re-notificacao a cada tick).
     */
    public function fire(Server $server, ?AlertRule $rule, string $metric, ?string $mount, string $level, ?float $value, array $detail = []): void
    {
        $aberto = $this->open($server->id, $metric, $mount);

        if ($aberto === null) {
            try {
                $incident = Incident::withoutAccountScope()->create([
                    'account_id' => $server->account_id,
                    'server_id' => $server->id,
                    'rule_id' => $rule?->id,
                    'metric' => $metric,
                    'mount' => $mount,
                    'level' => $level,
                    'status' => Incident::STATUS_FIRING,
                    'open_key' => Incident::openKey($server->id, $metric, $mount),
                    'value_at_fire' => $value,
                    'detail' => $detail,
                    'started_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                return; // corrida: outro tick abriu primeiro — no-op
            }

            $this->notifier->transition($incident, 'firing');

            return;
        }

        // Escalada (warning -> critical) no MESMO incidente; sem downgrade.
        // Avisa a mudanca de severidade.
        if ($aberto->level === 'warning' && $level === 'critical') {
            $aberto->forceFill([
                'level' => 'critical',
                'value_at_fire' => $value ?? $aberto->value_at_fire,
            ])->save();
            $this->notifier->transition($aberto, 'escalated');
        }
    }

    /** Resolve o incidente aberto (se houver): libera o open_key e notifica UMA vez. */
    public function resolve(int $serverId, string $metric, ?string $mount = null): void
    {
        $aberto = $this->open($serverId, $metric, $mount);
        if ($aberto === null) {
            return;
        }

        $aberto->forceFill([
            'status' => Incident::STATUS_RESOLVED,
            'resolved_at' => now(),
            'open_key' => null,
        ])->save();

        $this->notifier->transition($aberto, 'resolved');
    }
}
