<?php

namespace App\Billing;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Fatia 26 — cliente HTTP do Asaas (doc oficial, API v3). Sandbox-first: a base
 * URL e a chave vem SO do .env (config/billing.asaas) — nada hardcodado. A
 * autenticacao da API e o header `access_token` (o Asaas nao usa Bearer).
 *
 * PCI: este cliente NUNCA envia/recebe dado de cartao. O pagamento acontece na
 * fatura HOSPEDADA do Asaas (invoiceUrl da cobranca); com billingType
 * UNDEFINED o proprio cliente escolhe cartao/Pix/boleto la dentro.
 */
class AsaasClient
{
    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('billing.asaas.base_url'), '/') . '/v3')
            ->withHeaders([
                'access_token' => (string) config('billing.asaas.api_key'),
                'User-Agent' => 'msgautomation/1.0 (Laravel)',
            ])
            ->acceptJson()
            ->timeout(15);
    }

    /** POST /v3/customers — cria o cliente (name + cpfCnpj obrigatorios). */
    public function criarCustomer(array $dados): array
    {
        return $this->http()->post('/customers', $dados)->throw()->json();
    }

    /** POST /v3/subscriptions — cria a assinatura (billingType UNDEFINED = hospedado). */
    public function criarAssinatura(array $dados): array
    {
        return $this->http()->post('/subscriptions', $dados)->throw()->json();
    }

    /** DELETE /v3/subscriptions/{id} — cancela a assinatura (cobrancas futuras). */
    public function cancelarAssinatura(string $subscriptionId): array
    {
        return $this->http()->delete('/subscriptions/' . $subscriptionId)->throw()->json();
    }

    /**
     * GET /v3/subscriptions/{id}/payments — a PRIMEIRA cobranca em aberto da
     * assinatura; o invoiceUrl dela e a pagina de pagamento hospedada.
     */
    public function cobrancaEmAberto(string $subscriptionId): ?array
    {
        $lista = $this->http()
            ->get('/subscriptions/' . $subscriptionId . '/payments', ['status' => 'PENDING', 'limit' => 1])
            ->throw()->json();

        if (($lista['data'][0] ?? null) !== null) {
            return $lista['data'][0];
        }

        // Sem PENDING (ex.: ja OVERDUE): pega a mais recente de qualquer status.
        $lista = $this->http()
            ->get('/subscriptions/' . $subscriptionId . '/payments', ['limit' => 1])
            ->throw()->json();

        return $lista['data'][0] ?? null;
    }

    /** GET /v3/webhooks — configuracoes de webhook existentes. */
    public function listarWebhooks(): array
    {
        return $this->http()->get('/webhooks')->throw()->json();
    }

    /** POST /v3/webhooks — registra o webhook (authToken volta no header asaas-access-token). */
    public function criarWebhook(array $dados): array
    {
        return $this->http()->post('/webhooks', $dados)->throw()->json();
    }

    /** PUT /v3/webhooks/{id} — atualiza a config (URL/token/eventos) existente. */
    public function atualizarWebhook(string $id, array $dados): array
    {
        return $this->http()->put('/webhooks/' . $id, $dados)->throw()->json();
    }
}
