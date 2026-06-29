<?php

namespace App\Whatsapp\AutoReply;

use Illuminate\Support\Facades\Cache;

/**
 * Contadores de envio com TTL, escopados por account. Em producao o cache store e o
 * Redis do app (CACHE_STORE=redis), entao sao contadores em Redis. Em teste o store
 * e 'array' (deterministico/isolado).
 *
 * Boundary do dia em America/Sao_Paulo. Contadores incrementam SO no envio efetivo.
 */
class Throttle
{
    private function tz(): string
    {
        return (string) config('app.timezone');
    }

    public function minuteHits(int $accountId): int
    {
        return (int) Cache::get($this->minuteKey($accountId), 0);
    }

    public function dayHits(int $accountId): int
    {
        return (int) Cache::get($this->dayKey($accountId), 0);
    }

    public function secondsSinceLastSend(int $accountId): ?int
    {
        $ts = Cache::get($this->lastKey($accountId));

        return $ts === null ? null : max(0, now()->getTimestamp() - (int) $ts);
    }

    public function contactRecentlyReplied(int $accountId, string $jid): bool
    {
        return Cache::has($this->contactKey($accountId, $jid));
    }

    /** Registra um envio efetivo: incrementa contadores e marca o ultimo envio. */
    public function recordSend(int $accountId): void
    {
        $minuteKey = $this->minuteKey($accountId);
        Cache::add($minuteKey, 0, 120);
        Cache::increment($minuteKey);

        $dayKey = $this->dayKey($accountId);
        Cache::add($dayKey, 0, $this->secondsToEndOfDay());
        Cache::increment($dayKey);

        Cache::put($this->lastKey($accountId), now()->getTimestamp(), 3600);
    }

    public function markContactReplied(int $accountId, string $jid, int $seconds): void
    {
        Cache::put($this->contactKey($accountId, $jid), 1, max(1, $seconds));
    }

    private function minuteKey(int $accountId): string
    {
        return "autoreply:{$accountId}:min:" . now($this->tz())->format('YmdHi');
    }

    private function dayKey(int $accountId): string
    {
        return "autoreply:{$accountId}:day:" . now($this->tz())->format('Ymd');
    }

    private function lastKey(int $accountId): string
    {
        return "autoreply:{$accountId}:lastsend";
    }

    private function contactKey(int $accountId, string $jid): string
    {
        return "autoreply:{$accountId}:contact:" . sha1($jid);
    }

    private function secondsToEndOfDay(): int
    {
        $now = now($this->tz());

        return max(60, $now->copy()->endOfDay()->getTimestamp() - $now->getTimestamp() + 5);
    }
}
