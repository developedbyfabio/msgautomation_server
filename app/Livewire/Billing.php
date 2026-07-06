<?php

namespace App\Livewire;

use App\Auth\AreaAccess;
use App\Billing\AsaasClient;
use App\Billing\BillingState;
use App\Models\Account;
use App\Tenancy\AccountContext;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Fatia 26 — tela de ASSINATURA (/assinatura, owner-only). Inicia a assinatura
 * no Asaas e manda o cliente pro pagamento HOSPEDADO (invoiceUrl da cobranca):
 * cartao NUNCA toca o sistema — aqui so ficam os IDs (customer/subscription).
 * O retorno do cliente e informativo; a VERDADE do pagamento e o webhook
 * (PAYMENT_CONFIRMED/RECEIVED -> active). E a UNICA tela que a conta suspensa
 * alcanca (fora do gate account.operational) — pagar/reativar.
 */
#[Layout('components.layouts.app')]
class Billing extends Component
{
    public bool $confirmandoCancelamento = false;

    private function conta(): Account
    {
        return Account::query()->findOrFail(app(AccountContext::class)->id());
    }

    /** Inicia (ou retoma) a assinatura e redireciona pro pagamento hospedado. */
    public function assinar(AsaasClient $asaas, BillingState $maquina)
    {
        AreaAccess::authorizeOwnerAction(); // acao Livewire e forjavel (Fatia 22)
        $conta = $this->conta();

        if ($conta->document === null) {
            // Conta legacy/criada pelo admin: sem CPF/CNPJ nao ha cobranca.
            $this->dispatch('toast', message: 'Conta sem CPF/CNPJ cadastrado — cobranca nao se aplica.', type: 'error');

            return;
        }

        try {
            if ($conta->asaas_customer_id === null) {
                $customer = $asaas->criarCustomer([
                    'name' => $conta->razao_social ?: $conta->name,
                    'cpfCnpj' => $conta->document,
                    'email' => (string) auth()->user()?->email,
                    'mobilePhone' => $conta->phone,
                    'address' => $conta->endereco,
                    'addressNumber' => $conta->numero,
                    'complement' => $conta->complemento,
                    'province' => $conta->bairro,
                    'postalCode' => $conta->cep,
                    'externalReference' => 'account:' . $conta->id,
                ]);
                $conta->forceFill(['asaas_customer_id' => $customer['id']])->save();
            }

            // Assinatura nova quando nao existe OU quando a anterior foi cancelada
            // (canceled e terminal pra webhook; um novo checkout rearma).
            if ($conta->asaas_subscription_id === null || $conta->subscription_status === 'canceled') {
                $vencimento = $conta->subscription_status === 'trial' && $conta->trial_ends_at?->isFuture()
                    ? $conta->trial_ends_at // cobranca so DEPOIS do teste gratis
                    : now();
                $assinatura = $asaas->criarAssinatura([
                    'customer' => $conta->asaas_customer_id,
                    'billingType' => 'UNDEFINED', // HOSPEDADO: o cliente escolhe cartao/Pix/boleto na fatura do Asaas
                    'value' => (float) config('billing.plan.price'),
                    'nextDueDate' => $vencimento->toDateString(),
                    'cycle' => 'MONTHLY',
                    'description' => config('billing.plan.name') . ' — ' . config('app.name'),
                    'externalReference' => 'account:' . $conta->id,
                ]);
                $conta->forceFill(['asaas_subscription_id' => $assinatura['id']])->save();
                if ($conta->subscription_status === 'canceled') {
                    $maquina->transicionar($conta, 'overdue', 'painel:novo_checkout');
                }
            }

            $cobranca = $asaas->cobrancaEmAberto($conta->asaas_subscription_id);
            if (($cobranca['invoiceUrl'] ?? null) !== null) {
                // Pagina de pagamento HOSPEDADA do Asaas (PCI fora do sistema).
                return $this->redirect($cobranca['invoiceUrl'], navigate: false);
            }

            $this->dispatch('toast', message: 'Cobranca sendo gerada pelo Asaas — tente de novo em instantes.');
        } catch (\Throwable $e) {
            report($e); // nunca loga chave/segredo: so a excecao do HTTP client
            $this->dispatch('toast', message: 'Nao foi possivel falar com o sistema de pagamento agora. Tente de novo.', type: 'error');
        }
    }

    /** Abre de novo a fatura em aberto (quem fechou a aba antes de pagar). */
    public function abrirFatura(AsaasClient $asaas)
    {
        AreaAccess::authorizeOwnerAction();
        $conta = $this->conta();
        if ($conta->asaas_subscription_id === null) {
            return;
        }

        try {
            $cobranca = $asaas->cobrancaEmAberto($conta->asaas_subscription_id);
            if (($cobranca['invoiceUrl'] ?? null) !== null) {
                return $this->redirect($cobranca['invoiceUrl'], navigate: false);
            }
            $this->dispatch('toast', message: 'Nenhuma cobranca em aberto agora.');
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('toast', message: 'Nao foi possivel falar com o sistema de pagamento agora. Tente de novo.', type: 'error');
        }
    }

    public function pedirCancelamento(): void
    {
        AreaAccess::authorizeOwnerAction();
        $this->confirmandoCancelamento = true;
    }

    public function fecharCancelamento(): void
    {
        $this->confirmandoCancelamento = false;
    }

    public function cancelar(AsaasClient $asaas, BillingState $maquina): void
    {
        AreaAccess::authorizeOwnerAction();
        $conta = $this->conta();
        $this->confirmandoCancelamento = false;
        if ($conta->asaas_subscription_id === null || $conta->subscription_status === 'canceled') {
            return;
        }

        try {
            $asaas->cancelarAssinatura($conta->asaas_subscription_id);
            // NADA e apagado: dados intactos; o acesso fica como suspenso ate um
            // novo checkout (semantica registrada).
            $maquina->transicionar($conta, 'canceled', 'painel:cancelamento');
            $this->dispatch('toast', message: 'Assinatura cancelada.');
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('toast', message: 'Nao foi possivel cancelar agora. Tente de novo.', type: 'error');
        }
    }

    public function render()
    {
        $conta = $this->conta();

        return view('livewire.billing', [
            'conta' => $conta,
            'plano' => config('billing.plan'),
            'diasRestantes' => $conta->subscription_status === 'trial' && $conta->trial_ends_at !== null
                ? max(0, (int) now()->diffInDays($conta->trial_ends_at, false))
                : null,
            'statusLabel' => match ($conta->subscription_status) {
                'trial' => 'Periodo de teste',
                'active' => 'Ativa',
                'overdue' => 'Pagamento pendente',
                'suspended' => 'Suspensa por falta de pagamento',
                'canceled' => 'Cancelada',
                default => $conta->subscription_status,
            },
        ]);
    }
}
