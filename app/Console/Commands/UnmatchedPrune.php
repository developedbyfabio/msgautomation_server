<?php

namespace App\Console\Commands;

use App\Models\UnmatchedMessage;
use Illuminate\Console\Command;

/**
 * MATCH-1 — retencao do log de sem-match: apaga registros com mais de 30 dias
 * (todas as contas; e LOG operacional com prazo, nao trilha de auditoria —
 * diferente de proactive_consents, que nunca apaga).
 */
class UnmatchedPrune extends Command
{
    protected $signature = 'unmatched:prune {--days=30}';

    protected $description = 'Apaga mensagens sem-match com mais de N dias (default 30)';

    public function handle(): int
    {
        $dias = max(1, (int) $this->option('days'));
        $n = UnmatchedMessage::withoutAccountScope()
            ->where('created_at', '<', now()->subDays($dias))
            ->delete();

        $this->info("{$n} registro(s) de sem-match com mais de {$dias} dias apagado(s).");

        return self::SUCCESS;
    }
}
