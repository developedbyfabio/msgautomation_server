<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Channel;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * CH-2 — cria o canal WhatsApp Cloud API (oficial) da conta. Segredos (access
 * token, app secret) SEMPRE por prompt oculto — nunca em argumento/historico/
 * log — e cifrados no canal (padrao CH-1). channels.instance = phone_number_id
 * (a chave de roteamento do webhook, como o nome da instancia na Evolution).
 */
class ChannelCreateCloud extends Command
{
    protected $signature = 'msg:channel:create-cloud {--account= : ID da conta (default: a mais antiga)}';

    protected $description = 'Cria um canal WhatsApp Cloud API (credenciais por prompt oculto, cifradas)';

    public function handle(): int
    {
        $account = $this->option('account')
            ? Account::find((int) $this->option('account'))
            : Account::query()->oldest('id')->first();
        if (! $account) {
            $this->error('Conta nao encontrada.');

            return self::FAILURE;
        }

        $phoneNumberId = trim((string) $this->ask('phone_number_id (do painel da Meta, numero de TESTE)'));
        if (! preg_match('/^\d{5,20}$/', $phoneNumberId)) {
            $this->error('phone_number_id invalido (esperado: so digitos).');

            return self::FAILURE;
        }
        if (Channel::withoutAccountScope()->where('instance', $phoneNumberId)->exists()) {
            $this->error('Ja existe canal com esse phone_number_id.');

            return self::FAILURE;
        }

        $wabaId = trim((string) $this->ask('WABA id (WhatsApp Business Account id)'));
        $verifyToken = trim((string) $this->ask('verify_token do webhook (vazio = gero um)'));
        if ($verifyToken === '') {
            $verifyToken = Str::random(32);
        }

        $accessToken = trim((string) $this->secret('access token (oculto; temporario do painel vale ~23h)'));
        $appSecret = trim((string) $this->secret('app secret (oculto; em App settings > Basic)'));
        if ($accessToken === '' || $appSecret === '') {
            $this->error('access token e app secret sao obrigatorios.');

            return self::FAILURE;
        }

        $channel = Channel::withoutAccountScope()->create([
            'account_id' => $account->id,
            'instance' => $phoneNumberId, // roteamento: DTO.instance = metadata.phone_number_id
            'provider' => 'cloud_api',
            'webhook_token' => Str::random(48),
            'status' => 'disconnected', // vira connected no "verificar" / primeiro trafego
            'credentials' => [
                'access_token' => $accessToken,
                'phone_number_id' => $phoneNumberId,
                'waba_id' => $wabaId,
                'verify_token' => $verifyToken,
                'app_secret' => $appSecret,
            ],
        ]);

        $this->info("Canal cloud_api criado: id {$channel->id} (conta {$account->id}). Credenciais CIFRADAS no banco.");
        $this->line('Configure o webhook no app da Meta (produto WhatsApp > Configuration):');
        $this->line('  Callback URL: https://<seu-subdominio>/webhook/cloud/' . $channel->webhook_token);
        $this->line('  Verify token: ' . $verifyToken);
        $this->line('  Assinar o campo: messages');
        $this->warn('Token de acesso temporario expira em ~23h — token permanente (system user) e horizonte CH-4.');

        return self::SUCCESS;
    }
}
