<?php

namespace App\Jobs;

use App\Models\Group;
use App\Whatsapp\EvolutionApi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * S4 — busca o subject do grupo na Evolution (background) e grava em groups.
 * Display apenas; nao toca em matcher/freios. Falha de rede e silenciosa
 * (tenta de novo depois, via dedupe do resolver).
 */
class ResolveGroupName implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $accountId,
        public readonly string $jid,
    ) {
    }

    public function handle(EvolutionApi $api): void
    {
        try {
            $resp = $api->groupInfo($this->jid);
            if (! $resp->successful()) {
                return;
            }
            $j = $resp->json();
            $subject = data_get($j, 'subject')
                ?? data_get($j, 'data.subject')
                ?? data_get($j, '0.subject')
                ?? data_get($j, 'groupMetadata.subject');
        } catch (\Throwable) {
            return;
        }

        if (! is_string($subject) || $subject === '') {
            return;
        }

        Group::updateOrCreate(
            ['account_id' => $this->accountId, 'remote_jid' => $this->jid],
            ['subject' => $subject, 'resolved_at' => now()],
        );
    }
}
