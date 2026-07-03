<?php

namespace App\Models;

use App\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;

/**
 * Prompt 02 — evento da timeline do /logs. Escopado por conta (MT-0);
 * eventos GLOBAIS (account_id NULL, ex.: erro de sistema) sao gravados e
 * lidos via bypass NOMEADO (withoutAccountScope) na propria pagina.
 */
class SystemEvent extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'account_id', 'channel_id', 'type', 'level', 'title', 'detail', 'ref', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'detail' => 'array'];
    }

    /** Grava evento GLOBAL (sem conta) — erro de sistema. Nunca lanca (best-effort). */
    public static function global(string $level, string $title): void
    {
        try {
            static::withoutAccountScope()->create([
                'account_id' => null,
                'type' => 'erro_sistema',
                'level' => $level,
                'title' => mb_substr($title, 0, 200),
                'occurred_at' => now(),
            ]);
        } catch (\Throwable) {
            // best-effort: log de erro nunca pode derrubar o caminho principal
        }
    }
}
