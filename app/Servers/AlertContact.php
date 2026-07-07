<?php

namespace App\Servers;

use App\Tenancy\BelongsToAccount;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Servidores S3 — destinatario de alerta (roteamento). Escopo por conta (A1).
 * min_level filtra por severidade: 'warning' recebe warning e critical;
 * 'critical' recebe so critical.
 *
 * ESCOPO de servidores (Feature 2): server_ids (JSON) com a selecao; NULL/vazio
 * = todos. Precede o alvo legado (server_id > grupo > todos), que segue valendo
 * para linhas antigas nao migradas.
 *
 * JANELA de horario (Feature 1): window_mode 24h|custom + window_start/end
 * ('HH:MM') + weekends. Fuso America/Sao_Paulo na comparacao. FORA da janela o
 * WhatsApp DESTE contato e suprimido (descarte por-contato, sem acumulo) — mas
 * o incidente e a conversa "Alertas de Infraestrutura" seguem registrados
 * (AlertNotifier, upstream do envio): o fato nao se perde.
 */
class AlertContact extends Model
{
    use BelongsToAccount;

    /** Fuso de referencia das janelas (servidor roda UTC). */
    public const TZ = 'America/Sao_Paulo';

    protected $table = 'server_alert_contacts';

    protected $fillable = [
        'account_id', 'server_id', 'grupo', 'server_ids', 'name', 'phone', 'email',
        'min_level', 'window_mode', 'window_start', 'window_end', 'weekends', 'enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'weekends' => 'boolean',
            'server_ids' => 'array',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** Este contato deve receber um incidente deste nivel, neste servidor? (severidade + escopo) */
    public function matches(string $level, Server $server): bool
    {
        if (! $this->enabled) {
            return false;
        }
        // Severidade: critical passa por qualquer min_level; warning so se min_level=warning.
        if ($this->min_level === 'critical' && $level !== 'critical') {
            return false;
        }

        return $this->coversServer($server);
    }

    /** Escopo de servidores: selecao (server_ids) precede o alvo legado. */
    public function coversServer(Server $server): bool
    {
        $ids = $this->server_ids;
        if (is_array($ids) && $ids !== []) {
            return in_array($server->id, array_map('intval', $ids), true);
        }
        // Legado (linhas antigas): servidor especifico > grupo > todos.
        if ($this->server_id !== null) {
            return $this->server_id === $server->id;
        }
        if ($this->grupo !== null) {
            return $this->grupo === $server->grupo;
        }

        return true; // sem escopo = todos os servidores da conta
    }

    /**
     * O contato esta DENTRO da sua janela de recebimento AGORA (fuso America/
     * Sao_Paulo)? Fim de semana e checado independentemente da janela de horario.
     * '24h' (ou janela incompleta) = sempre. Janela que cruza a meia-noite
     * (inicio > fim, ex.: 22:00-06:00) e tratada.
     */
    public function withinWindow(?CarbonInterface $now = null): bool
    {
        $now = ($now ? $now->copy() : Carbon::now())->setTimezone(self::TZ);

        // Fim de semana: se nao recebe, corta sabado/domingo (mesmo dentro do horario).
        if (! $this->weekends && $now->isWeekend()) {
            return false;
        }

        if ($this->window_mode !== 'custom' || $this->window_start === null || $this->window_end === null) {
            return true; // 24h
        }

        $t = $now->format('H:i');           // 'HH:MM' zero-padded -> compara lexicografico
        $start = $this->window_start;
        $end = $this->window_end;

        return $start <= $end
            ? ($t >= $start && $t <= $end)   // janela no mesmo dia (08:00-18:00)
            : ($t >= $start || $t <= $end);  // janela cruzando a meia-noite (22:00-06:00)
    }
}
