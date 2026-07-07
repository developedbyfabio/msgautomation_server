<?php

namespace App\Servers;

use Illuminate\Support\Facades\Cache;

/**
 * Servidores S1 — buffer RECENTE de amostras por servidor. NAO e historico
 * nem TSDB (decisao travada da Fase 0): janela deslizante das ultimas MAX
 * amostras com TTL — some sozinho se o agente parar de reportar.
 *
 * Vive no CACHE do app (CACHE_STORE=redis em dev/producao -> as chaves caem
 * no Redis com o prefixo do projeto e EXPIRE nativo; array na suite). Decisao
 * deliberada vs LPUSH/LTRIM cru no facade Redis:
 *  1. a suite roda com CACHE_STORE=array e NAO sobrepoe REDIS_* — Redis cru
 *     nos testes contaminaria o Redis real da instancia DEV;
 *  2. cada servidor tem UM agente (escritor unico por chave): o
 *     read-modify-write do Cache e seguro aqui, atomicidade de lista nao faz falta.
 * Um flush do Redis so atrasa a histerese futura (S2) — o watchdog nao depende
 * disto (last_seen_at e duravel no MySQL).
 *
 * Dimensionamento: MAX=60 amostras a 15-30s de cadencia = ~15-30 min, folga
 * pra qualquer "for duration" razoavel; TTL=1h.
 */
class MetricsBuffer
{
    public const MAX_SAMPLES = 60;

    public const TTL_SECONDS = 3600;

    /** Chave no cache (no Redis vira {REDIS_PREFIX}{cache_prefix}servers:buffer:{id}). */
    public function key(int $serverId): string
    {
        return 'servers:buffer:'.$serverId;
    }

    /** Empilha uma amostra (mais recente primeiro), aparando na janela. */
    public function push(int $serverId, array $sample): void
    {
        $samples = $this->samples($serverId);
        array_unshift($samples, $sample);

        Cache::put($this->key($serverId), array_slice($samples, 0, self::MAX_SAMPLES), self::TTL_SECONDS);
    }

    /** Amostras da janela (mais recente primeiro). Vazio se nunca/expirado. */
    public function samples(int $serverId): array
    {
        return (array) Cache::get($this->key($serverId), []);
    }

    /** Ultima amostra ou null. */
    public function latest(int $serverId): ?array
    {
        return $this->samples($serverId)[0] ?? null;
    }

    /** Descarta o buffer do servidor (exclusao/regeneracao nao precisa, mas exclusao usa). */
    public function forget(int $serverId): void
    {
        Cache::forget($this->key($serverId));
    }
}
