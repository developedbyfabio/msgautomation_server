<?php

namespace App\Console\Commands;

use App\Channels\Evolution\ChannelProvisioner;
use App\Channels\Evolution\EvolutionProvider;
use App\Models\Account;
use App\Models\Channel;
use Illuminate\Console\Command;

/**
 * MT-2 passo 3 — MIGRACAO DO WEBHOOK VIVO pra rota por token (GATE do Fabio):
 *
 *   php artisan evolution:webhook:migrate            -> SO MOSTRA o plano (dry-run)
 *   php artisan evolution:webhook:migrate --apply    -> aplica (rodar SO com o ok)
 *
 * Depois de aplicar e VALIDAR (mensagem real chegando pela rota nova no journal),
 * a aceitacao do secret global foi REMOVIDA (MT-2). Reversao completa exige:
 * git revert do commit da remocao + --rollback aqui (a URL antiga so volta a
 * autenticar com o middleware revertido).
 */
class WebhookMigrate extends Command
{
    protected $signature = 'evolution:webhook:migrate {--account= : ID da conta (default: a mais antiga)} {--apply : Aplica a mudanca} {--rollback : Volta pra URL antiga (header secreto)}';

    protected $description = 'Migra o webhook da instancia pra rota por token (dry-run por default; --apply com gate)';

    public function handle(EvolutionProvider $provider, ChannelProvisioner $provisioner): int
    {
        $account = $this->option('account')
            ? Account::find((int) $this->option('account'))
            : Account::query()->oldest('id')->first();
        $channel = $account ? Channel::defaultFor($account->id) : null;
        if (! $channel) {
            $this->error('Canal nao encontrado.');

            return self::FAILURE;
        }
        if ($channel->webhook_token === null) {
            $this->error('Canal sem webhook_token — rode a provisao antes.');

            return self::FAILURE;
        }

        $api = $provider->api($channel);
        $atual = $provisioner->webhookUrlAtual($api) ?? '(sem webhook configurado)';
        $tokenUrl = $provisioner->tokenUrl($channel);
        $legadoUrl = rtrim((string) config('services.evolution.webhook_url'), '/');

        $alvo = $this->option('rollback') ? $legadoUrl : $tokenUrl;
        $mascarada = $this->mascarar($alvo, (string) $channel->webhook_token);

        $this->info("Instancia: {$channel->instance} (conta {$channel->account_id})");
        $this->line('Webhook ATUAL:  ' . $this->mascarar($atual, (string) $channel->webhook_token));
        $this->line('Webhook ALVO:   ' . $mascarada . ($this->option('rollback') ? '  [ROLLBACK: URL antiga + header secreto]' : '  [rota por token]'));

        if (! $this->option('apply') && ! $this->option('rollback')) {
            $this->warn('DRY-RUN: nada alterado. Rode com --apply SO apos o gate do Fabio no chat.');

            return self::SUCCESS;
        }

        if ($this->option('rollback')) {
            $header = (string) config('services.webhook.header');
            $secret = (string) config('services.webhook.secret');
            if ($secret === '') {
                $this->error('Rollback exige o secret global no env.');

                return self::FAILURE;
            }
            $resp = $api->setWebhook($legadoUrl, ['MESSAGES_UPSERT'], [$header => $secret]);
        } else {
            $resp = $api->setWebhook($tokenUrl, ['MESSAGES_UPSERT'], []);
        }

        if (! $resp->successful()) {
            $this->error("Falha ao configurar webhook (HTTP {$resp->status()}).");

            return self::FAILURE;
        }

        $this->info('Webhook atualizado. VALIDE com mensagem real (journal) antes de qualquer passo seguinte.');

        return self::SUCCESS;
    }

    /** Nunca exibe o token inteiro (nem em terminal): abcd...wxyz. */
    private function mascarar(string $url, string $token): string
    {
        if ($token !== '' && str_contains($url, $token)) {
            $curto = substr($token, 0, 4) . '...' . substr($token, -4);

            return str_replace($token, $curto, $url);
        }

        return $url;
    }
}
