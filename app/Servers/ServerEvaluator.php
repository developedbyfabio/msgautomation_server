<?php

namespace App\Servers;

/**
 * Servidores S2 — avaliacao de UM servidor: watchdog (last_seen_at DURAVEL no
 * MySQL) + regras de metrica com HISTERESE sobre o buffer efemero. Nao guarda
 * estado proprio: le buffer + regras + incidentes abertos e converge — por
 * construcao, rodar N vezes chega no mesmo lugar (idempotente).
 *
 * HISTERESE ("for duration") — definicao registrada: andando da amostra MAIS
 * RECENTE para tras, TODAS as amostras violam o limiar consecutivamente, com
 * pelo menos MIN_SAMPLES amostras e span observado (t_recente - t_antiga da
 * sequencia) >= for_duration do nivel. Pico curto quebra a sequencia; buffer
 * insuficiente (servidor novo/flush) NAO dispara por metrica — falso positivo
 * por falta de dado e coberto pelo watchdog, nao pelas metricas.
 *
 * RESOLUCAO com a mesma histerese (anti-flapping): so resolve quando ha
 * sequencia LIMPA (abaixo do limiar de warning; do critical se a regra nao
 * tem warning) cobrindo warning_for_s (min. 60s). Sem amostras -> nao resolve
 * (incidente e duravel; flush do Redis nao fecha incidente).
 *
 * WATCHDOG com PRECEDENCIA: gap = agora - last_seen_at. gap >= warning do
 * watchdog => servidor STALE: as metricas NAO avaliam (dado velho nao abre
 * nem fecha incidente de metrica); so o watchdog transiciona. Voltou a
 * reportar (gap < warning) => watchdog resolve e as metricas voltam.
 * last_seen_at NULL (nunca reportou) => watchdog nao se aplica ("aguardando
 * primeiro contato", selo da S1).
 */
class ServerEvaluator
{
    /** Minimo de amostras consecutivas para confirmar condicao (ou limpeza). */
    public const MIN_SAMPLES = 3;

    /** Span minimo (s) da sequencia limpa para resolver (regras com for_duration curto). */
    private const MIN_RESOLVE_SPAN_S = 60;

    public function __construct(
        private MetricsBuffer $buffer,
        private IncidentManager $incidents,
    ) {}

    public function evaluate(Server $server): void
    {
        $rules = $this->rulesFor($server);

        // --- watchdog primeiro (dead man's switch; precedencia sobre metricas)
        $watchdog = $rules['watchdog'] ?? null;
        $gap = $server->last_seen_at !== null
            ? (int) abs($server->last_seen_at->diffInSeconds(now()))
            : null;

        $stale = false;
        if ($watchdog !== null && $gap !== null) {
            $warnGap = (float) ($watchdog->warning_threshold ?? $watchdog->critical_threshold);
            $stale = $gap >= $warnGap;

            if ($gap >= (float) $watchdog->critical_threshold) {
                $this->incidents->fire($server, $watchdog, 'watchdog', null, 'critical', (float) $gap, ['gap_s' => $gap]);
            } elseif ($stale) {
                $this->incidents->fire($server, $watchdog, 'watchdog', null, 'warning', (float) $gap, ['gap_s' => $gap]);
            } else {
                $this->incidents->resolve($server->id, 'watchdog');
            }
        }

        if ($stale) {
            return; // dado velho nao avalia metrica (nem abre, nem fecha)
        }

        // --- metricas sobre o buffer efemero
        $samples = $this->buffer->samples($server->id);

        foreach (['cpu', 'ram', 'swap', 'load'] as $metric) {
            $rule = $rules[$metric] ?? null;
            if ($rule === null) {
                continue;
            }
            $series = $this->series($samples, $metric);
            $this->applyRule($server, $rule, $metric, null, $series);
        }

        if (isset($rules['disk'])) {
            foreach ($this->mounts($samples) as $mount) {
                $this->applyRule($server, $rules['disk'], 'disk', $mount, $this->series($samples, 'disk', $mount));
            }
        }
    }

    /**
     * Regras EFETIVAS por metrica: especifica do servidor > global da conta.
     * A precedencia vale inclusive para enabled=false — sobrescrita desligada
     * SILENCIA a metrica naquele servidor (nao cai na global).
     */
    public function rulesFor(Server $server): array
    {
        $todas = AlertRule::withoutAccountScope()
            ->where('account_id', $server->account_id)
            ->where(fn ($q) => $q->whereNull('server_id')->orWhere('server_id', $server->id))
            ->get();

        $efetivas = [];
        foreach (AlertRule::METRICS as $metric) {
            $regra = $todas->first(fn ($r) => $r->metric === $metric && $r->server_id === $server->id)
                ?? $todas->first(fn ($r) => $r->metric === $metric && $r->server_id === null);
            if ($regra !== null && $regra->enabled) {
                $efetivas[$metric] = $regra;
            }
        }

        return $efetivas;
    }

    /** Converge o estado do incidente da metrica[/particao] para o alvo da janela. */
    private function applyRule(Server $server, AlertRule $rule, string $metric, ?string $mount, array $series): void
    {
        if ($series === []) {
            return; // sem dado: nem abre, nem fecha (watchdog cobre o "sumiu")
        }

        // Nivel-alvo: critical > warning > null (cada um com a propria duracao).
        [$nivel, $valor, $janela] = $this->targetLevel($rule, $series);

        if ($nivel !== null) {
            $this->incidents->fire($server, $rule, $metric, $mount, $nivel, $valor, $janela);

            return;
        }

        // Resolucao anti-flapping: sequencia LIMPA (abaixo do limiar de
        // warning) cobrindo a janela de warning (min. 60s).
        $limpeza = (float) ($rule->warning_threshold ?? $rule->critical_threshold);
        $spanResolve = max((int) $rule->warning_for_s, self::MIN_RESOLVE_SPAN_S);
        if ($this->holds($series, fn (float $v) => $v < $limpeza, $spanResolve)) {
            $this->incidents->resolve($server->id, $metric, $mount);
        }
    }

    /** [nivel|null, valor mais recente, detalhe da janela]. */
    private function targetLevel(AlertRule $rule, array $series): array
    {
        $atual = $series[0]['v'];

        if ($this->holds($series, fn (float $v) => $v >= (float) $rule->critical_threshold, (int) $rule->critical_for_s)) {
            return ['critical', $atual, $this->windowDetail($series, (int) $rule->critical_for_s)];
        }

        if ($rule->warning_threshold !== null
            && $this->holds($series, fn (float $v) => $v >= (float) $rule->warning_threshold, (int) $rule->warning_for_s)) {
            return ['warning', $atual, $this->windowDetail($series, (int) $rule->warning_for_s)];
        }

        return [null, $atual, []];
    }

    /**
     * A condicao $cond vale CONSECUTIVAMENTE da amostra mais recente para tras
     * por >= $forSeconds de span observado e >= MIN_SAMPLES amostras?
     */
    private function holds(array $series, callable $cond, int $forSeconds): bool
    {
        $count = 0;
        $newest = null;
        $oldest = null;

        foreach ($series as $ponto) { // mais recente primeiro
            if (! $cond($ponto['v'])) {
                break; // sequencia quebrada (pico/normalizacao no meio)
            }
            $newest ??= $ponto['t'];
            $oldest = $ponto['t'];
            $count++;
        }

        return $count >= self::MIN_SAMPLES && ($newest - $oldest) >= $forSeconds;
    }

    /** Serie [['t' => epoch, 'v' => float], ...] da metrica (mais recente primeiro). */
    private function series(array $samples, string $metric, ?string $mount = null): array
    {
        $serie = [];
        foreach ($samples as $sample) {
            $v = $this->valueOf($sample, $metric, $mount);
            if ($v === null) {
                continue; // amostra sem a metrica (ex.: sem swap) nao entra
            }
            $serie[] = ['t' => (int) ($sample['received_at'] ?? 0), 'v' => $v];
        }

        return $serie;
    }

    /** Valor da metrica numa amostra normalizada da S1 (null = nao reportado). */
    private function valueOf(array $sample, string $metric, ?string $mount): ?float
    {
        return match ($metric) {
            'cpu' => isset($sample['cpu_pct']) ? (float) $sample['cpu_pct'] : null,
            'ram' => isset($sample['mem_pct']) ? (float) $sample['mem_pct'] : null,
            'swap' => isset($sample['swap_pct']) ? (float) $sample['swap_pct'] : null,
            // load1 POR NUCLEO; sem cpu_count no payload, comparacao ABSOLUTA
            // contra o mesmo limiar (limitacao registrada no relatorio).
            'load' => isset($sample['load'][0])
                ? (float) $sample['load'][0] / max(1, (int) ($sample['cpu_count'] ?? 1))
                : null,
            'disk' => collect($sample['disks'] ?? [])->firstWhere('mount', $mount)['pct'] ?? null,
            default => null,
        };
    }

    /** Particoes vistas na janela (uniao de todas as amostras). */
    private function mounts(array $samples): array
    {
        $mounts = [];
        foreach ($samples as $sample) {
            foreach ($sample['disks'] ?? [] as $disk) {
                $mounts[(string) $disk['mount']] = true;
            }
        }

        return array_keys($mounts);
    }

    /** Detalhe diagnostico da janela que confirmou a condicao. */
    private function windowDetail(array $series, int $forSeconds): array
    {
        $janela = array_slice($series, 0, 12);

        return [
            'for_s' => $forSeconds,
            'amostras' => array_map(fn ($p) => ['t' => $p['t'], 'v' => round($p['v'], 2)], $janela),
        ];
    }
}
