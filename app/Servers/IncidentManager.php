<?php

namespace App\Servers;

use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Servidores S2 — maquina de estado do incidente (firing -> acknowledged ->
 * resolved), DURAVEL no MySQL. Invariantes:
 *
 *  - UM incidente ativo por (servidor, metrica[, particao]): open_key unique
 *    decide corridas no banco (create duplicado -> catch -> no-op).
 *  - UMA acao de notificacao por transicao (firing/escalated/resolved) — o
 *    notifier usa ref idempotente; rodar a avaliacao 2x nao duplica nada.
 *  - ack NAO fecha (segue aberto e monitorado; silencia repeticao); resolve
 *    fecha e libera o open_key para um incidente futuro da mesma metrica.
 *  - Escalada warning -> critical atualiza o MESMO incidente (nunca abre um
 *    segundo); nunca ha downgrade critical -> warning (so resolve).
 *  - A5: escalada FURA o ack. Se o incidente estava acknowledged (o dono
 *    reconheceu o WARNING), a subida para critical o devolve a `firing` e limpa
 *    o ack — o critical DEVE notificar e voltar a re-notificar; o dono nunca
 *    reconheceu esta severidade.
 */
class IncidentManager
{
    public function __construct(private AlertNotifier $notifier) {}

    /** Incidente ABERTO (firing|acknowledged) do servidor+metrica[+mount], se houver. */
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
        // A5: FURA o ack — volta a firing e limpa o reconhecimento (era do
        // warning), para o critical notificar e voltar a re-notificar.
        if ($aberto->level === 'warning' && $level === 'critical') {
            $aberto->forceFill([
                'level' => 'critical',
                'status' => Incident::STATUS_FIRING,
                'acknowledged_at' => null,
                'acknowledged_by' => null,
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

    /** Ack do dono (tela Incidentes): silencia repeticao, incidente segue aberto. */
    public function acknowledge(Incident $incident, int $userId): void
    {
        if ($incident->status !== Incident::STATUS_FIRING) {
            return;
        }

        $incident->forceFill([
            'status' => Incident::STATUS_ACKNOWLEDGED,
            'acknowledged_at' => now(),
            'acknowledged_by' => $userId,
        ])->save();
    }
}
