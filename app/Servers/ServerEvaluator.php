<?php

namespace App\Servers;

/**
 * Servidores S2 — avaliacao de UM servidor: watchdog (last_seen_at DURAVEL no
 * MySQL) + regras de metrica com HISTERESE sobre o buffer efemero. Nao guarda
 * estado proprio: le buffer + regras + incidentes abertos e converge — por
 * construcao, rodar N vezes chega no mesmo lugar (idempotente).
 *
 * HISTERESE ("for duration") — A3, medida em SEGUNDOS (nao em contagem fixa de
 * amostras): andando da amostra MAIS RECENTE para tras, a condicao vale
 * CONTINUAMENTE por >= for_duration do nivel. "Continuamente" = a sequencia de
 * amostras que violam nao tem buraco maior que MAX_GAP (cadencia esperada x
 * fator) — uma amostra perdida e tolerada; duas seguidas quebram a prova de
 * continuidade (conservador: sem dado, sem alerta). Assim a LATENCIA do alerta
 * segue o for_duration, independente da cadencia de ingestao ou de uma amostra
 * que caiu. Pico curto quebra a sequencia; buffer insuficiente (servidor novo/
 * flush) NAO dispara por metrica — o "sem dado" e papel do watchdog.
 *
 * RESOLUCAO com debounce SIMETRICO (A4, anti-flapping): so resolve quando ha
 * sequencia LIMPA (abaixo do limiar de warning; do critical se a regra nao tem
 * warning) continua por resolve_for_s (default = warning_for_s, min. 60s).
 * Sem amostras -> nao resolve (incidente e duravel; flush do Redis nao fecha).
 *
 * WATCHDOG com PRECEDENCIA: gap = agora - last_seen_at (SEMPRE hora de
 * RECEBIMENTO server-side — A2; o ts do agente nunca decide). gap >= warning
 * do watchdog => servidor STALE: as metricas NAO avaliam (dado velho nao abre
 * nem fecha incidente de metrica); so o watchdog transiciona. Voltou a
 * reportar (gap < warning) => watchdog resolve e as metricas voltam.
 * last_seen_at NULL (nunca reportou) => watchdog nao se aplica ("aguardando
 * primeiro contato", selo da S1).
 */
class ServerEvaluator
{
    /** Fator sobre a cadencia esperada para o gap maximo tolerado entre amostras. */
    private const MAX_GAP_FACTOR = 2.5;

    /** Piso do debounce de resolucao (s), quando a regra nao define resolve_for_s. */
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

        // Disco: resolucao POR PARTICAO (S4). Cada mount reportado casa a regra
        // mais especifica; desligada -> particao silenciada; nenhuma -> ignora.
        foreach ($this->mounts($samples) as $mount) {
            $rule = $this->diskRuleFor($server, $mount);
            if ($rule === null) {
                continue; // sem regra efetiva (ou sobrescrita da particao desligada)
            }
            $this->applyRule($server, $rule, 'disk', $mount, $this->series($samples, 'disk', $mount));
        }
    }

    /**
     * Regra de disco EFETIVA para uma particao (S4). Precedencia, mais
     * especifica primeiro: (servidor, mount) > (servidor, NULL) > (global,
     * NULL). enabled=false na regra escolhida SILENCIA a particao (retorna
     * null: nem abre, nem fecha — como a sobrescrita desligada de metrica).
     */
    public function diskRuleFor(Server $server, string $mount): ?AlertRule
    {
        $regras = AlertRule::withoutAccountScope()
            ->where('account_id', $server->account_id)
            ->where('metric', 'disk')
            ->where(fn ($q) => $q->whereNull('server_id')->orWhere('server_id', $server->id))
            ->get();

        $escolhida = $regras->first(fn ($r) => $r->server_id === $server->id && $r->mount === $mount)
            ?? $regras->first(fn ($r) => $r->server_id === $server->id && $r->mount === null)
            ?? $regras->first(fn ($r) => $r->server_id === null && $r->mount === null);

        return $escolhida !== null && $escolhida->enabled ? $escolhida : null;
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

        // Resolucao com debounce SIMETRICO (A4): sequencia LIMPA (abaixo do
        // limiar de warning) continua por resolve_for_s. Default = warning_for_s
        // (janela de subida), piso 60s — a metrica precisa "ficar boa" pelo
        // mesmo tempo que precisou "ficar ruim" antes de fechar o incidente.
        $limpeza = (float) ($rule->warning_threshold ?? $rule->critical_threshold);
        // resolve_for_s EXPLICITO e honrado como esta (escolha do dono); NULL cai
        // no default seguro: janela de subida (warning_for_s), com piso de 60s.
        $spanResolve = $rule->resolve_for_s !== null
            ? (int) $rule->resolve_for_s
            : max((int) $rule->warning_for_s, self::MIN_RESOLVE_SPAN_S);
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
     * A condicao $cond vale CONTINUAMENTE por >= $forSeconds (A3)? Anda da
     * amostra mais recente para tras enquanto $cond e verdadeira E o buraco ate
     * a amostra anterior nao passa de MAX_GAP (cadencia x fator — tolera UMA
     * amostra perdida). O span coberto pela sequencia (t_recente - t_antiga)
     * precisa alcancar $forSeconds. Latencia = for_duration, desacoplada da
     * cadencia. forSeconds<=0 (nao usado por metrica; watchdog nao passa por
     * aqui) confirma com a primeira amostra.
     */
    private function holds(array $series, callable $cond, int $forSeconds): bool
    {
        $maxGap = (int) ceil(max(1, (int) config('servers.cadence_s', 30)) * self::MAX_GAP_FACTOR);

        $newest = null;
        $oldest = null;
        $prev = null;
        $count = 0;

        foreach ($series as $ponto) { // mais recente primeiro
            if (! $cond($ponto['v'])) {
                break; // sequencia quebrada (pico/normalizacao no meio)
            }
            if ($prev !== null && ($prev - $ponto['t']) > $maxGap) {
                break; // buraco de dados: sem prova de continuidade a partir daqui
            }
            $newest ??= $ponto['t'];
            $oldest = $ponto['t'];
            $prev = $ponto['t'];
            $count++;
        }

        if ($count === 0) {
            return false;
        }
        if ($forSeconds <= 0) {
            return true; // janela nula: uma amostra basta
        }

        // Precisa de >= 2 pontos (um span exige dois instantes) cobrindo forSeconds.
        return $count >= 2 && ($newest - $oldest) >= $forSeconds;
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
