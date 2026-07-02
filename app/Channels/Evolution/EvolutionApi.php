<?php

namespace App\Channels\Evolution;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cliente de GERENCIA da Evolution (criar instancia, configurar webhook, QR, estado).
 * CH-1: detalhe INTERNO do EvolutionProvider — construa via provider->api(canal),
 * que resolve as credenciais (canal cifrado -> fallback env). Nada aqui envia
 * mensagem de conversa; sao chamadas de administracao da instancia.
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

    /**
     * Desconecta (logout) a instancia — derruba a sessao do WhatsApp, exigindo
     * novo QR. Evolution v2.3.7: DELETE /instance/logout/{instance}.
     */
    public function logout(): Response
    {
        return $this->http()->delete("/instance/logout/{$this->instance}");
    }

    /**
     * Metadados de um grupo (subject/nome). Evolution v2.3.7:
     * GET /group/findGroupInfos/{instance}?groupJid=...  (endpoint A CONFIRMAR;
     * tratamos varios shapes de retorno no resolver). So leitura.
     */
    public function groupInfo(string $groupJid): Response
    {
        return $this->http()->get("/group/findGroupInfos/{$this->instance}", ['groupJid' => $groupJid]);
    }
}
