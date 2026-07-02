<?php

namespace App\Channels\Evolution;

use App\Models\Account;
use App\Models\Channel;
use Illuminate\Support\Str;

/**
 * MT-2 — provisiona o CANAL da conta e a instancia correspondente na Evolution:
 *  - nome de instancia UNICO por conta (conta-{id}-{slug});
 *  - webhook_token proprio (rota por token do MT-0);
 *  - credenciais NO CANAL (cifradas; o env e so o DEFAULT de provisionamento);
 *  - instancia criada na Evolution se faltar (POST /instance/create — endpoint
 *    ja usado em producao desde a Camada 1) + webhook apontando pra ROTA POR
 *    TOKEN (sem header de secret — o token na URL autentica).
 *
 * Idempotente: canal existente e reaproveitado; instancia existente nao e
 * recriada; o webhook de uma instancia JA CONFIGURADA com URL diferente NUNCA
 * e trocado por aqui (a migracao do webhook VIVO e o comando com gate
 * evolution:webhook:migrate --apply).
 */
class ChannelProvisioner
{
    public function __construct(private EvolutionProvider $provider)
    {
    }

    public function provision(Account $account): Channel
    {
        $channel = Channel::defaultFor($account->id);

        if (! $channel) {
            $instance = $this->uniqueInstanceName($account);
            $channel = Channel::withoutAccountScope()->create([
                'account_id' => $account->id,
                'instance' => $instance,
                'provider' => 'evolution',
                'webhook_token' => Str::random(48),
                'status' => 'disconnected',
                'credentials' => $this->credenciaisDefault($instance),
            ]);
        }

        // Canal pre-MT-2 sem credenciais/token: completa (aditivo, nada sobrescrito).
        if (empty($channel->credentials)) {
            $channel->update(['credentials' => $this->credenciaisDefault((string) $channel->instance)]);
        }
        if ($channel->webhook_token === null) {
            $channel->update(['webhook_token' => Str::random(48)]);
        }

        $api = $this->provider->api($channel);

        if (! $api->instanceExists()) {
            $resp = $api->createInstance();
            if (! $resp->successful()) {
                throw new \RuntimeException("Evolution: falha ao criar instancia '{$channel->instance}' (HTTP {$resp->status()}).");
            }
        }

        // Webhook: configura SO se ainda nao ha URL configurada (ou se ja e a nossa
        // por token). Instancia viva com outra URL fica INTOCADA (gate do migrate).
        $atual = $this->webhookUrlAtual($api);
        $alvo = $this->tokenUrl($channel);
        if ($atual === null || $atual === '' || $atual === $alvo) {
            $resp = $api->setWebhook($alvo, ['MESSAGES_UPSERT'], []);
            if (! $resp->successful()) {
                throw new \RuntimeException("Evolution: falha ao configurar webhook de '{$channel->instance}' (HTTP {$resp->status()}).");
            }
        }

        return $channel->fresh();
    }

    /** URL do webhook POR TOKEN deste canal (o token na URL autentica o canal). */
    public function tokenUrl(Channel $channel): string
    {
        return rtrim((string) config('services.evolution.webhook_url'), '/') . '/' . $channel->webhook_token;
    }

    /** URL configurada HOJE na instancia (null = sem webhook/indisponivel). */
    public function webhookUrlAtual(EvolutionApi $api): ?string
    {
        try {
            $resp = $api->findWebhook();
            if (! $resp->successful()) {
                return null;
            }

            return data_get($resp->json(), 'url') ?? data_get($resp->json(), 'webhook.url');
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{base_url: string, apikey: string, instance: string} */
    private function credenciaisDefault(string $instance): array
    {
        return [
            'base_url' => (string) config('services.evolution.base_url'),
            'apikey' => (string) config('services.evolution.api_key'),
            'instance' => $instance,
        ];
    }

    private function uniqueInstanceName(Account $account): string
    {
        $slug = Str::slug((string) $account->name) ?: 'conta';
        $nome = Str::limit("conta-{$account->id}-{$slug}", 40, '');

        while (Channel::withoutAccountScope()->where('instance', $nome)->exists()) {
            $nome = Str::limit("conta-{$account->id}-{$slug}", 34, '') . '-' . Str::lower(Str::random(4));
        }

        return $nome;
    }
}
