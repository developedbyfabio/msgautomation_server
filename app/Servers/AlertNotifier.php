<?php

namespace App\Servers;

use App\Models\SystemEvent;

/**
 * Servidores — acao de notificacao por transicao de incidente.
 *
 * MODO SILENCIOSO (flag servers.notifications_enabled OFF, default S2): cada
 * transicao registra UM SystemEvent "teria notificado..." (ref idempotente por
 * incidente+transicao) e marca notified_firing_at/notified_resolved_at — para o
 * dono calibrar limiares vendo nos Logs o que sairia, SEM enviar nada.
 *
 * MODO CANAL (flag ON, S3): a transicao NAO envia nem loga aqui. O ENVIO e
 * responsabilidade do job SendServerAlert, despachado pelo servers:evaluate ao
 * fim do tick (fila, agrupado por conta). O "pendente" e o proprio estado
 * persistido do incidente (notified_level != level; notified_resolved_at NULL)
 * — o job envia e marca. Assim o envio nunca ocorre no request nem dentro da
 * avaliacao, e um rack caindo vira UMA mensagem agrupada, nao dezenas.
 * NENHUMA referencia a transporte/Http aqui, de proposito.
 */
class AlertNotifier
{
    /** $transition: firing | escalated | resolved. */
    public function transition(Incident $incident, string $transition): void
    {
        // Modo canal (ON): a transicao nao faz nada aqui — o job SendServerAlert
        // (despachado pelo command) le o estado pendente, envia e marca.
        if (config('servers.notifications_enabled')) {
            return;
        }

        // Modo silencioso (OFF): trilha nos Logs + marcas (comportamento S2).
        $this->registrar($incident, $transition, '[silencioso] Teria notificado: ');

        if ($transition === 'firing' && $incident->notified_firing_at === null) {
            $incident->forceFill(['notified_firing_at' => now()])->save();
        }
        if ($transition === 'resolved' && $incident->notified_resolved_at === null) {
            $incident->forceFill(['notified_resolved_at' => now()])->save();
        }
    }

    /** SystemEvent com ref unica por (incidente, transicao) — best-effort. */
    private function registrar(Incident $incident, string $transition, string $prefixo): void
    {
        $server = $incident->server()->withoutGlobalScopes()->first();
        $nome = $server?->name ?? ('#'.$incident->server_id);
        $metrica = AlertRule::LABELS[$incident->metric] ?? $incident->metric;
        $particao = $incident->mount !== null ? " ({$incident->mount})" : '';

        $titulo = match ($transition) {
            'firing' => "{$prefixo}{$nome} — {$metrica}{$particao} {$incident->level}"
                .($incident->value_at_fire !== null ? " (valor {$incident->value_at_fire})" : ''),
            'escalated' => "{$prefixo}{$nome} — {$metrica}{$particao} escalou para critical",
            'resolved' => "{$prefixo}{$nome} — {$metrica}{$particao} normalizado",
            default => "{$prefixo}{$nome} — {$metrica}{$particao} {$transition}",
        };

        try {
            SystemEvent::withoutAccountScope()->firstOrCreate(
                ['ref' => 'srv-incident:'.$incident->id.':'.$transition],
                [
                    'account_id' => $incident->account_id,
                    'type' => 'servidores',
                    'level' => match (true) {
                        $transition === 'resolved' => 'info',
                        $incident->level === 'critical' || $transition === 'escalated' => 'error',
                        default => 'warning',
                    },
                    'title' => mb_substr($titulo, 0, 200),
                    'detail' => [
                        'incident_id' => $incident->id,
                        'server_id' => $incident->server_id,
                        'metric' => $incident->metric,
                        'mount' => $incident->mount,
                        'level' => $incident->level,
                        'transition' => $transition,
                        'value' => $incident->value_at_fire,
                    ],
                    'occurred_at' => now(),
                ],
            );
        } catch (\Throwable) {
            // best-effort: log nunca derruba a avaliacao
        }
    }
}
