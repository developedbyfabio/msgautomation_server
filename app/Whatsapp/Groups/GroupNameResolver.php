<?php

namespace App\Whatsapp\Groups;

use App\Jobs\ResolveGroupName;
use App\Models\Group;
use App\Whatsapp\EvolutionApi;
use Illuminate\Support\Facades\Cache;

/**
 * S4 — resolve o NOME (subject) de um grupo, com cache em DB (tabela groups).
 *  - nameFor(): so leitura do cache (DB), usada no render (NUNCA bate na Evolution).
 *  - ensure(): se nao ha nome em cache, dispara o job que busca na Evolution e grava.
 *    Dedupe por 5 min pra nao buscar a cada mensagem/poll.
 *  - resolveNow(): busca AGORA na Evolution e grava (usado pelo job e pelo botao
 *    "atualizar nome" sob demanda). So leitura na Evolution; display apenas.
 */
class GroupNameResolver
{
    public function __construct(private EvolutionApi $api)
    {
    }

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
        if (! Cache::add($this->dedupeKey($accountId, $jid), 1, 300)) {
            return;
        }

        ResolveGroupName::dispatch($accountId, $jid);
    }

    /**
     * Busca o subject na Evolution AGORA e grava. Retorna o nome ou null (falha/sem
     * subject). Limpa o dedupe para nao competir com o ensure. Falha de rede e silenciosa.
     */
    public function resolveNow(int $accountId, string $jid): ?string
    {
        if (! str_ends_with($jid, '@g.us')) {
            return null;
        }

        try {
            $resp = $this->api->groupInfo($jid);
            if (! $resp->successful()) {
                return null;
            }
            $j = $resp->json();
            $subject = data_get($j, 'subject')
                ?? data_get($j, 'data.subject')
                ?? data_get($j, '0.subject')
                ?? data_get($j, 'groupMetadata.subject');
        } catch (\Throwable) {
            return null;
        }

        if (! is_string($subject) || $subject === '') {
            return null;
        }

        Group::updateOrCreate(
            ['account_id' => $accountId, 'remote_jid' => $jid],
            ['subject' => $subject, 'resolved_at' => now()],
        );
        Cache::forget($this->dedupeKey($accountId, $jid));

        return $subject;
    }

    private function dedupeKey(int $accountId, string $jid): string
    {
        return "group_resolve:{$accountId}:{$jid}";
    }
}
