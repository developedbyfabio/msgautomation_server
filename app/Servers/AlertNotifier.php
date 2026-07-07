<?php

namespace App\Servers;

use App\Models\SystemEvent;

/**
 * Servidores S2 — ACAO de notificacao por transicao de incidente. 100% MUDA
 * nesta fatia: com config('servers.notifications_enabled') = false (default),
 * cada transicao registra UM SystemEvent "teria notificado..." (ref
 * idempotente por incidente+transicao — rodar a avaliacao N vezes nao duplica)
 * para o dono calibrar limiares vendo nos Logs exatamente o que sairia.
 *
 * A S3 liga o canal REAL colocando o envio atras do flag (o branch ON abaixo
 * ja existe e hoje tambem so registra, com a ressalva "canal nao implementado"
 * — ligar o flag por engano na S2 nao envia nada). NENHUMA referencia a
 * Sender/ProviderRegistry/Http aqui, de proposito.
 */
class AlertNotifier
{
    /** $transition: firing | escalated | resolved. */
    public function transition(Incident $incident, string $transition): void
    {
        $silencioso = ! config('servers.notifications_enabled');

        if ($silencioso) {
            $this->registrar($incident, $transition, '[silencioso] Teria notificado: ');
        } else {
            // S3: envio real de WhatsApp entra AQUI (job em fila). Ate la, o
            // flag ligado nao pode enviar nada — so registra a ressalva.
            $this->registrar($incident, $transition, '[canal nao implementado — S3] Teria notificado: ');
        }

        // Marca a acao de notificacao da transicao (idempotencia da maquina de
        // estado; a S3 reusa estas marcas para nunca re-enviar).
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
