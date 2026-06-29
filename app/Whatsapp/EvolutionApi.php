<?php

namespace App\Whatsapp;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cliente de GERENCIA da Evolution (criar instancia, configurar webhook, QR, estado).
 * Separado do EvolutionDriver (que so normaliza payloads de entrada).
 *
 * Camada 1: nada aqui envia mensagem. Sao chamadas de administracao da instancia.
 */
class EvolutionApi
{
    private string $base;
    private string $key;
    private string $instance;

    public function __construct(?string $base = null, ?string $key = null, ?string $instance = null)
    {
        $this->base = rtrim($base ?? (string) config('services.evolution.base_url'), '/');
        $this->key = $key ?? (string) config('services.evolution.api_key');
        $this->instance = $instance ?? (string) config('services.evolution.instance');
    }

    public function instance(): string
    {
        return $this->instance;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->base)
            ->withHeaders(['apikey' => $this->key])
            ->acceptJson()
            ->timeout(20);
    }

    /** Lista instancias (usado pra checar existencia). */
    public function fetchInstances(): Response
    {
        return $this->http()->get('/instance/fetchInstances');
    }

    public function instanceExists(): bool
    {
        $resp = $this->fetchInstances();
        if (! $resp->successful()) {
            return false;
        }

        foreach ((array) $resp->json() as $item) {
            $name = data_get($item, 'name') ?? data_get($item, 'instance.instanceName') ?? data_get($item, 'instanceName');
            if ($name === $this->instance) {
                return true;
            }
        }

        return false;
    }

    public function createInstance(): Response
    {
        return $this->http()->post('/instance/create', [
            'instanceName' => $this->instance,
            'integration' => 'WHATSAPP-BAILEYS',
            'qrcode' => false,
        ]);
    }

    /** Configura o webhook POR INSTANCIA (evento de mensagem recebida). */
    public function setWebhook(string $url, array $events, array $headers): Response
    {
        return $this->http()->post("/webhook/set/{$this->instance}", [
            'webhook' => [
                'enabled' => true,
                'url' => $url,
                'headers' => $headers,
                'byEvents' => false,
                'base64' => false,
                'events' => $events,
            ],
        ]);
    }

    public function findWebhook(): Response
    {
        return $this->http()->get("/webhook/find/{$this->instance}");
    }

    /** Conecta / obtem QR (base64). */
    public function connect(): Response
    {
        return $this->http()->get("/instance/connect/{$this->instance}");
    }

    public function connectionState(): Response
    {
        return $this->http()->get("/instance/connectionState/{$this->instance}");
    }
}
