<?php

namespace App\Whatsapp\Groups;

use App\Jobs\ResolveGroupName;
use App\Models\Group;
use Illuminate\Support\Facades\Cache;

/**
 * S4 — resolve o NOME (subject) de um grupo, com cache em DB (tabela groups).
 *  - nameFor(): so leitura do cache (DB), usada no render (NUNCA bate na Evolution).
 *  - ensure(): se nao ha nome em cache, dispara o job que busca na Evolution e grava.
 *    Dedupe por 5 min pra nao buscar a cada mensagem/poll.
 */
class GroupNameResolver
{
    public function nameFor(int $accountId, string $jid): ?string
    {
        $subject = Group::query()->where('account_id', $accountId)->where('remote_jid', $jid)->value('subject');

        return ($subject !== null && $subject !== '') ? $subject : null;
    }

    public function ensure(int $accountId, string $jid): void
    {
        if (! str_ends_with($jid, '@g.us')) {
            return;
        }
        if ($this->nameFor($accountId, $jid) !== null) {
            return; // ja resolvido
        }
        // Dedupe: dispara no maximo 1x a cada 5 min por grupo.
        if (! Cache::add("group_resolve:{$accountId}:{$jid}", 1, 300)) {
            return;
        }

        ResolveGroupName::dispatch($accountId, $jid);
    }
}
