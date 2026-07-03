<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Channel;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * CH-2 — cria (ou, com --update, CORRIGE) o canal WhatsApp Cloud API da conta.
 * Segredos (access token, app secret, verify_token) SEMPRE por prompt oculto —
 * nunca em argumento/historico/log — e cifrados no canal (padrao CH-1/S5).
 * channels.instance = phone_number_id (chave de roteamento do webhook).
 *
 * --update: atualiza SO as credenciais do canal existente (mesma conta + mesmo
 * phone_number_id). O webhook_token NUNCA muda — a Callback URL ja configurada
 * na Meta continua valida. api_version nao e por canal (config global
 * services.cloud_api.graph_version) — nao ha o que perguntar aqui.
 */
class ChannelCreateCloud extends Command
{
    protected $signature = 'msg:channel:create-cloud
        {--account= : ID da conta (default: a mais antiga)}
        {--update : Atualiza as credenciais do canal cloud EXISTENTE (nao cria; webhook token preservado)}';

    protected $description = 'Cria ou atualiza (--update) um canal WhatsApp Cloud API (credenciais por prompt oculto, cifradas)';

    public function handle(): int
    {
        $account = $this->option('account')
            ? Account::find((int) $this->option('account'))
            : Account::query()->oldest('id')->first();
        if (! $account) {
            $this->error('Conta nao encontrada.');

            return self::FAILURE;
        }

        $update = (bool) $this->option('update');

        // Ordem fixa dos campos (rotulos explicitos contra troca de valores):
        // phone_number_id -> waba_id -> access_token -> app_secret -> verify_token.
        $phoneNumberId = trim((string) $this->ask('phone_number_id (ID NUMERICO do numero no painel da Meta — nao e o telefone)'));
        if (! preg_match('/^\d{5,20}$/', $phoneNumberId)) {
            $this->error('phone_number_id invalido (esperado: so digitos).');

            return self::FAILURE;
        }

        $existente = Channel::withoutAccountScope()
            ->where('account_id', $account->id)
            ->where('instance', $phoneNumberId)
            ->first();

        if ($update) {
            if ($existente === null) {
                $this->error("Nao existe canal com esse phone_number_id na conta {$account->id} — rode sem --update pra criar.");

                return self::FAILURE;
            }
            if ($existente->provider !== 'cloud_api') {
                $this->error("O canal {$existente->id} desse identificador nao e cloud_api ({$existente->provider}) — nada foi tocado.");

                return self::FAILURE;
            }
        } elseif ($existente !== null
            || Channel::withoutAccountScope()->where('instance', $phoneNumberId)->exists()) {
            $this->error('Ja existe canal com esse phone_number_id. Pra corrigir credenciais sem recriar (webhook token preservado), rode com --update.');

            return self::FAILURE;
        }

        $wabaId = trim((string) $this->ask('waba_id (WhatsApp Business Account id, NUMERICO — nao e o app id)'));

        $accessToken = trim((string) $this->secret('access_token (o TOKEN GRANDE da Meta, comeca com EAA... — oculto)'));
        $appSecret = trim((string) $this->secret('app_secret (App settings > Basic, hex curto — NAO e o access token; oculto)'));
        if ($accessToken === '' || $appSecret === '') {
            $this->error('access_token e app_secret sao obrigatorios.');

            return self::FAILURE;
        }
        if (! str_starts_with($accessToken, 'EAA')) {
            $this->warn('Aviso: access_token da Meta normalmente comeca com "EAA" — confira se nao inverteu os campos.');
        }

        $rotuloVerify = 'verify_token (a string CURTA que VOCE inventou pro webhook — NAO o token EAA... da Meta; '
            . ($update ? 'vazio = mantem o atual; oculto)' : 'vazio = gero um; oculto)');
        $verifyToken = trim((string) $this->secret($rotuloVerify));
        if ($verifyToken !== '' && str_starts_with($verifyToken, 'EAA')
            && ! $this->confirm('Isso parece um ACCESS TOKEN da Meta, nao um verify_token. Usar mesmo assim?', false)) {
            $this->error('Abortado sem gravar nada — rode de novo com o verify_token certo.');

            return self::FAILURE;
        }

        $credentials = [
            'access_token' => $accessToken,
            'phone_number_id' => $phoneNumberId,
            'waba_id' => $wabaId,
            'app_secret' => $appSecret,
        ];

        if ($update) {
            $verifyGerado = false;
            $credentials['verify_token'] = $verifyToken !== ''
                ? $verifyToken
                : (string) ($existente->credentials['verify_token'] ?? '');
            // SO credenciais: instance, webhook_token e status ficam como estao.
            $existente->update(['credentials' => $credentials]);
            $channel = $existente;
            $this->info("Canal cloud_api {$channel->id} (conta {$account->id}) ATUALIZADO. Webhook token preservado; credenciais cifradas.");
        } else {
            $verifyGerado = $verifyToken === '';
            $credentials['verify_token'] = $verifyGerado ? Str::random(32) : $verifyToken;
            $channel = Channel::withoutAccountScope()->create([
                'account_id' => $account->id,
                'instance' => $phoneNumberId, // roteamento: DTO.instance = metadata.phone_number_id
                'provider' => 'cloud_api',
                'webhook_token' => Str::random(48),
                'status' => 'disconnected', // vira connected no "verificar" / primeiro trafego
                'credentials' => $credentials,
            ]);
            $this->info("Canal cloud_api criado: id {$channel->id} (conta {$account->id}). Credenciais CIFRADAS no banco.");
        }

        $this->line('Configure/confira o webhook no app da Meta (produto WhatsApp > Configuration):');
        $this->line('  Callback URL: https://wa.nextgest.com.br/webhook/cloud/' . $channel->webhook_token);
        $this->line('  Verify token: ' . ($verifyGerado
            ? $credentials['verify_token'] . ' (GERADO agora — anote; e a unica exibicao)'
            : $this->mascarar($credentials['verify_token'])));
        $this->line('  Assinar o campo: messages');
        $this->warn('Token de acesso temporario expira em ~23h — token permanente (system user) e horizonte CH-4.');

        return self::SUCCESS;
    }

    /** Mostra so as pontas (confere sem expor): "abc…yz (11 chars)". */
    private function mascarar(string $valor): string
    {
        $len = mb_strlen($valor);
        if ($len === 0) {
            return '(vazio!)';
        }
        if ($len <= 6) {
            return mb_substr($valor, 0, 1) . str_repeat('*', $len - 1) . " ({$len} chars)";
        }

        return mb_substr($valor, 0, 3) . '…' . mb_substr($valor, -2) . " ({$len} chars)";
    }
}
