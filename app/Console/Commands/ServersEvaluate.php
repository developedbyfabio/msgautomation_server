<?php

namespace App\Console\Commands;

use App\Jobs\SendServerAlert;
use App\Servers\AlertRuleDefaults;
use App\Servers\Server;
use App\Servers\ServerEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Servidores S2 — tick de avaliacao (30-60s via scheduler; os testes chamam
 * direto). FORA do ciclo de request. Idempotente por construcao (avaliador
 * converge; transicoes protegidas por open_key unique + refs de notificacao)
 * e SEM sobreposicao: alem do withoutOverlapping do schedule, um lock proprio
 * de cache protege invocacoes diretas/concorrentes — execucao atrasada nao
 * atropela a proxima.
 *
 * 100% MUDO na S2: transicoes registram SystemEvent (AlertNotifier, flag
 * servers.notifications_enabled OFF). Nenhum WhatsApp, nenhum job.
 */
class ServersEvaluate extends Command
{
    protected $signature = 'servers:evaluate';

    protected $description = 'Avalia os servidores monitorados (watchdog + regras) e transiciona incidentes';

    public const LOCK_KEY = 'servers:evaluate:lock';

    public function handle(ServerEvaluator $evaluator): int
    {
        $lock = Cache::lock(self::LOCK_KEY, 50);

        if (! $lock->get()) {
            $this->info('servers:evaluate ja em execucao — pulando (lock).');

            return self::SUCCESS;
        }

        try {
            // Cross-account por construcao (comando roda sem sessao): bypass
            // NOMEADO + account_id explicito em tudo que o avaliador grava.
            $servers = Server::withoutAccountScope()->where('enabled', true)->get();

            // Contas novas ganham as regras padrao de forma lazy (a migration
            // seedou as existentes; firstOrCreate e barato e idempotente).
            foreach ($servers->pluck('account_id')->unique() as $accountId) {
                AlertRuleDefaults::ensureFor((int) $accountId);
            }

            foreach ($servers as $server) {
                $evaluator->evaluate($server);
            }

            // S3 — canal atras do flag: despacha o ENVIO (fila) por conta com
            // pendencia, DEPOIS de avaliar tudo (agrupa transicoes do tick numa
            // mensagem). Flag OFF: nada e despachado (silencioso fica no notifier).
            if (config('servers.notifications_enabled')) {
                foreach ($servers->pluck('account_id')->unique() as $accountId) {
                    if (SendServerAlert::hasPending((int) $accountId)) {
                        SendServerAlert::dispatch((int) $accountId)->onQueue(config('servers.alert_queue', 'default'));
                    }
                }
            }

            $this->info("Avaliados: {$servers->count()} servidor(es).");
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
