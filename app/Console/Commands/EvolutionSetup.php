<?php

namespace App\Console\Commands;

use App\Channels\Evolution\ChannelProvisioner;
use App\Models\Account;
use Illuminate\Console\Command;

/**
 * MT-2 — prepara o canal da CONTA: provisiona canal + instancia na Evolution +
 * webhook por TOKEN (via ChannelProvisioner, idempotente). Instancia viva com
 * webhook apontando pra OUTRA URL fica INTOCADA — a migracao do webhook vivo e
 * o `evolution:webhook:migrate --apply` (gate). NAO conecta numero (QR = gate).
 */
class EvolutionSetup extends Command
{
    protected $signature = 'evolution:setup {--account= : ID da conta (default: a mais antiga)}';

    protected $description = 'Provisiona canal + instancia + webhook por token da conta (idempotente; nao toca webhook vivo divergente)';

    public function handle(ChannelProvisioner $provisioner): int
    {
        $account = $this->option('account')
            ? Account::find((int) $this->option('account'))
            : Account::query()->oldest('id')->first();
        if (! $account) {
            $this->error('Conta nao encontrada.');

            return self::FAILURE;
        }

        try {
            $channel = $provisioner->provision($account);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Canal OK: id {$channel->id}, instancia '{$channel->instance}', provider {$channel->provider}.");
        $this->line('Credenciais: no canal (cifradas). Webhook: rota por token (se a instancia nao tinha outra URL viva).');

        return self::SUCCESS;
    }
}
