<?php

namespace App\Channels\CloudApi;

use App\Models\Account;
use App\Models\Channel;
use Illuminate\Support\Str;

/**
 * Prompt 24a — logica PURA de salvar o canal WhatsApp Cloud API (sem I/O de CLI),
 * consumida pelo comando `msg:channel:create-cloud` e pela UI (24b). Credenciais
 * cifradas via `channels.credentials` (encrypted:array) — nunca em claro, nunca
 * logadas. Escopo ESTRITO a conta recebida (nada cross-tenant).
 *
 * Anti-swap virou ERRO estruturado (nao mais confirm interativo): verify_token
 * comecando com "EAA" e rejeitado (indicio de access token no campo errado).
 * access_token sem "EAA" e AVISO nao bloqueante (retornado no result).
 */
class SaveCloudChannel
{
    /**
     * @param array{phone_number_id?:string,waba_id?:string,access_token?:string,app_secret?:string,verify_token?:string} $input
     */
    public function handle(Account $account, array $input, bool $update = false): SaveCloudChannelResult
    {
        $phoneNumberId = trim((string) ($input['phone_number_id'] ?? ''));
        if (! preg_match('/^\d{5,20}$/', $phoneNumberId)) {
            return SaveCloudChannelResult::fail('phone_number_id invalido (esperado: so digitos, 5 a 20).');
        }

        $existente = Channel::withoutAccountScope()
            ->where('account_id', $account->id)
            ->where('instance', $phoneNumberId)
            ->first();

        if ($update) {
            if ($existente === null) {
                return SaveCloudChannelResult::fail("Nao existe canal com esse phone_number_id na conta {$account->id} — crie sem 'update'.");
            }
            if ($existente->provider !== 'cloud_api') {
                return SaveCloudChannelResult::fail("O canal {$existente->id} desse identificador nao e cloud_api ({$existente->provider}) — nada foi tocado.");
            }
        } elseif ($existente !== null
            || Channel::withoutAccountScope()->where('instance', $phoneNumberId)->exists()) {
            return SaveCloudChannelResult::fail('Ja existe canal com esse phone_number_id. Para corrigir credenciais sem recriar (webhook token preservado), use atualizar.');
        }

        $wabaId = trim((string) ($input['waba_id'] ?? ''));
        $accessToken = trim((string) ($input['access_token'] ?? ''));
        $appSecret = trim((string) ($input['app_secret'] ?? ''));
        if ($accessToken === '' || $appSecret === '') {
            return SaveCloudChannelResult::fail('access_token e app_secret sao obrigatorios.');
        }

        // Anti-swap (agora ERRO, nao confirm): verify_token com "EAA" = access token no campo errado.
        $verifyToken = trim((string) ($input['verify_token'] ?? ''));
        if ($verifyToken !== '' && str_starts_with($verifyToken, 'EAA')) {
            return SaveCloudChannelResult::fail('O verify_token parece um ACCESS TOKEN da Meta (comeca com "EAA"). Confira: o verify_token e uma string curta que voce inventa; o token "EAA..." vai no campo access_token.');
        }

        // Aviso NAO bloqueante: access_token da Meta normalmente comeca com "EAA".
        $warning = str_starts_with($accessToken, 'EAA')
            ? null
            : 'Aviso: access_token da Meta normalmente comeca com "EAA" — confira se nao inverteu os campos.';

        $credentials = [
            'access_token' => $accessToken,
            'phone_number_id' => $phoneNumberId,
            'waba_id' => $wabaId,
            'app_secret' => $appSecret,
        ];

        if ($update) {
            // vazio = mantem o verify atual; SO credenciais (instance/webhook_token/status intactos).
            $credentials['verify_token'] = $verifyToken !== ''
                ? $verifyToken
                : (string) ($existente->credentials['verify_token'] ?? '');
            $existente->update(['credentials' => $credentials]);

            return SaveCloudChannelResult::success($existente->fresh(), verifyGerado: false, warning: $warning);
        }

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

        return SaveCloudChannelResult::success($channel, verifyGerado: $verifyGerado, warning: $warning);
    }
}
