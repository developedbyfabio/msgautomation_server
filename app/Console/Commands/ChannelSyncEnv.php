<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Channel;
use Illuminate\Console\Command;

/**
 * MT-2 — migra as credenciais do ENV pro registro do CANAL (cifradas), uma vez,
 * idempotente: canal com credentials preenchido NUNCA e sobrescrito. O env vira
 * so o default de provisionamento de canal novo (documentado no provisioner).
 * Valores jamais exibidos.
 */
class ChannelSyncEnv extends Command
{
    protected $signature = 'msg:channel:sync-env {--account= : ID da conta (default: a mais antiga)}';

    protected $description = 'Preenche channels.credentials (cifrado) com os valores atuais do env — idempotente';

    public function handle(): int
    {
        $account = $this->option('account')
            ? Account::find((int) $this->option('account'))
            : Account::query()->oldest('id')->first();
        if (! $account) {
            $this->error('Conta nao encontrada.');

            return self::FAILURE;
        }

        $channel = Channel::defaultFor($account->id);
        if (! $channel) {
            $this->error("Conta {$account->id} nao tem canal.");

            return self::FAILURE;
        }

        if (! empty($channel->credentials)) {
            $this->info("Canal {$channel->id} ({$channel->instance}): credenciais JA preenchidas — nada a fazer (idempotente).");

            return self::SUCCESS;
        }

        $channel->update(['credentials' => [
            'base_url' => (string) config('services.evolution.base_url'),
            'apikey' => (string) config('services.evolution.api_key'),
            'instance' => (string) $channel->instance,
        ]]);

        $this->info("Canal {$channel->id} ({$channel->instance}): credenciais migradas do env (cifradas no banco; valores nao exibidos).");

        return self::SUCCESS;
    }
}
