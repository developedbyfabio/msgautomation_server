<?php

namespace App\Console\Commands;

use App\Channels\CloudApi\SaveCloudChannel;
use App\Models\Account;
use App\Models\Channel;
use Illuminate\Console\Command;

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

    public function handle(SaveCloudChannel $saver): int
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
        // Early-exit de UX (falha antes de pedir segredos); a fonte da verdade da
        // validacao/persistencia e o Action SaveCloudChannel (chamado ao fim).
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

        $rotuloVerify = 'verify_token (a string CURTA que VOCE inventou pro webhook — NAO o token EAA... da Meta; '
            . ($update ? 'vazio = mantem o atual; oculto)' : 'vazio = gero um; oculto)');
        $verifyToken = trim((string) $this->secret($rotuloVerify));

        // Delegacao: validacao (obrigatorios), anti-swap (verify "EAA" = erro),
        // montagem+cifra e create/update ficam no Action (fonte unica).
        $r = $saver->handle($account, [
            'phone_number_id' => $phoneNumberId,
            'waba_id' => $wabaId,
            'access_token' => $accessToken,
            'app_secret' => $appSecret,
            'verify_token' => $verifyToken,
        ], $update);

        if ($r->warning !== null) {
            $this->warn($r->warning); // aviso nao bloqueante (access_token sem "EAA")
        }
        if (! $r->ok) {
            $this->error($r->error);

            return self::FAILURE;
        }

        $channel = $r->channel;
        $verifyGerado = $r->verifyGerado;
        $credentials = $channel->credentials;
        $this->info($update
            ? "Canal cloud_api {$channel->id} (conta {$account->id}) ATUALIZADO. Webhook token preservado; credenciais cifradas."
            : "Canal cloud_api criado: id {$channel->id} (conta {$account->id}). Credenciais CIFRADAS no banco.");

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
