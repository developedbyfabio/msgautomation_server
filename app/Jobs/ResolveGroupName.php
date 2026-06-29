<?php

namespace App\Jobs;

use App\Whatsapp\Groups\GroupNameResolver;
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

    public function handle(GroupNameResolver $resolver): void
    {
        $resolver->resolveNow($this->accountId, $this->jid);
    }
}
