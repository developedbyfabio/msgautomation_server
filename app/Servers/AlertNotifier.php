<?php

namespace App\Servers;

use App\Models\SystemEvent;
use App\Whatsapp\SystemConversation;

/**
 * Servidores — acao de notificacao por transicao de incidente.
 *
 * SEMPRE (F2, independente do flag): grava a transicao como mensagem na conversa
 * de SISTEMA "Alertas de Infraestrutura" da conta — historico visivel no
 * Atendimento (mesmo mudo: vira o registro do que "teria sido enviado"). Isso
 * NAO envia WhatsApp e NAO toca o pipeline (a conversa de sistema e isolada).
 *
 * MODO SILENCIOSO (flag servers.notifications_enabled OFF, default S2): alem da
 * conversa, registra UM SystemEvent "teria notificado..." (ref idempotente) e
 * marca notified_firing_at/notified_resolved_at — para calibrar sem enviar.
 *
 * MODO CANAL (flag ON, S3): a transicao NAO envia nem loga aqui. O ENVIO e
 * responsabilidade do job SendServerAlert, despachado pelo servers:evaluate ao
 * fim do tick (fila, agrupado por conta). Assim o envio nunca ocorre no request
 * nem dentro da avaliacao. NENHUMA referencia a transporte/Http aqui.
 */
class AlertNotifier
{
    /** $transition: firing | escalated | resolved. */
    public function transition(Incident $incident, string $transition): void
    {
        // F2 — grava na conversa de sistema em TODA transicao (mudo ou nao).
        // Direto (record), sem evento de dominio: nao dispara robo/Kanban.
        $this->registrarNaConversa($incident, $transition);

        // Modo canal (ON): o envio real fica com o job SendServerAlert.
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

    /**
     * F2 — grava a transicao como mensagem na conversa de sistema (idempotente
     * por incidente+transicao). Best-effort: nunca derruba a avaliacao.
     */
    private function registrarNaConversa(Incident $incident, string $transition): void
    {
        try {
            // MESMO texto configurado que vai pro WhatsApp (rotacao + variaveis):
            // firing/escalated usam a mensagem do nivel (indice = notify_count);
            // resolved usa o texto de resolucao.
            $resolver = app(AlertMessageResolver::class);
            $texto = $transition === 'resolved' ? $resolver->resolved($incident) : $resolver->firing($incident);

            app(SystemConversation::class)->record(
                $incident->account_id,
                $texto,
                'srv-alert:'.$incident->id.':'.$transition,
            );
        } catch (\Throwable) {
            // best-effort: a conversa e so exibicao; nao pode quebrar o alerta
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
