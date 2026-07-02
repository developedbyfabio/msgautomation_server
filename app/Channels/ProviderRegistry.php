<?php

namespace App\Channels;

use App\Channels\Evolution\EvolutionProvider;
use App\Models\Channel;

/**
 * CH-1 — resolucao de provider POR CANAL (substitui o binding unico
 * WhatsappGateway -> EvolutionDriver). O map e extensivel: a CH-2 registra
 * 'cloud_api'; testes registram dublês via register().
 */
class ProviderRegistry
{
    /** @var array<string, class-string<ChannelProvider>|ChannelProvider> */
    private array $providers = [
        'evolution' => EvolutionProvider::class,
        'cloud_api' => \App\Channels\CloudApi\CloudApiProvider::class, // CH-2
    ];

    /** Registra/substitui um provider (CH-2 e dublês de teste). */
    public function register(string $key, ChannelProvider|string $provider): void
    {
        $this->providers[$key] = $provider;
    }

    public function get(string $key): ChannelProvider
    {
        $provider = $this->providers[$key] ?? null;
        if ($provider === null) {
            throw new UnknownChannelProviderException($key);
        }

        return $provider instanceof ChannelProvider ? $provider : app($provider);
    }

    /** Provider do canal (canal antigo sem a coluna preenchida = evolution). */
    public function for(Channel $channel): ChannelProvider
    {
        return $this->get((string) ($channel->provider ?: 'evolution'));
    }
}
