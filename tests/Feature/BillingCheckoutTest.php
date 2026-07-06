<?php

namespace Tests\Feature;

use App\Livewire\Billing;
use App\Models\Account;
use App\Models\User;
use App\Tenancy\AccountContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fatia 26 — checkout HOSPEDADO (Asaas fake via Http::fake — nenhum byte sai
 * pra rede; a chave da suite e fake pelo phpunit.xml). O que se prova: cria
 * customer+assinatura com os dados PF/PJ da conta, persiste os IDs, manda o
 * cliente pro invoiceUrl (pagina do Asaas) e NENHUM dado de cartao existe em
 * nenhuma request — PCI fora do sistema por construcao.
 */
class BillingCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'api-sandbox.asaas.com/v3/customers' => Http::response(['id' => 'cus_teste_1', 'name' => 'Maria'], 200),
            'api-sandbox.asaas.com/v3/subscriptions' => Http::response(['id' => 'sub_teste_1', 'status' => 'ACTIVE'], 200),
            'api-sandbox.asaas.com/v3/subscriptions/sub_teste_1/payments*' => Http::response([
                'data' => [['id' => 'pay_teste_1', 'status' => 'PENDING', 'invoiceUrl' => 'https://sandbox.asaas.com/i/fatura123']],
            ], 200),
            'api-sandbox.asaas.com/*' => Http::response(['deleted' => true], 200),
        ]);

        $this->account = Account::create(['name' => 'Maria da Silva']);
        $this->account->forceFill([
            'subscription_status' => 'trial',
            'trial_ends_at' => now()->addDays(5),
            'person_type' => 'pf',
            'document' => '52998224725',
            'phone' => '41999998888',
            'cep' => '80010000', 'endereco' => 'Rua XV', 'numero' => '100',
            'bairro' => 'Centro', 'cidade' => 'Curitiba', 'uf' => 'PR',
        ])->save();

        $this->owner = User::create(['name' => 'Maria', 'email' => 'maria@pag.local', 'password' => Hash::make('senha-forte-123')]);
        $this->owner->accounts()->attach($this->account->id, ['role' => 'owner']);
    }

    private function comoOwner(): \Livewire\Features\SupportTesting\Testable
    {
        $this->actingAs($this->owner);
        app(AccountContext::class)->set($this->account->id);

        return Livewire::test(Billing::class);
    }

    public function test_assinar_cria_customer_e_assinatura_persiste_ids_e_redireciona_pra_fatura_hospedada(): void
    {
        $this->comoOwner()
            ->call('assinar')
            ->assertRedirect('https://sandbox.asaas.com/i/fatura123'); // pagamento HOSPEDADO

        $this->account->refresh();
        $this->assertSame('cus_teste_1', $this->account->asaas_customer_id);
        $this->assertSame('sub_teste_1', $this->account->asaas_subscription_id);

        // Customer criado com o CPF/CNPJ da conta (validado na Fatia 25), na base SANDBOX.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api-sandbox.asaas.com/v3/customers')
                && $request['cpfCnpj'] === '52998224725'
                && $request->hasHeader('access_token');
        });
        // Assinatura: plano unico, ciclo mensal, HOSPEDADO (UNDEFINED = cliente
        // escolhe cartao/Pix/boleto NA PAGINA DO ASAAS) e vencimento no fim do trial.
        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || ! str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/v3/subscriptions')) {
                return false; // ignora o GET das cobrancas (mesmo prefixo de URL)
            }
            $dados = $request->data();

            return ($dados['billingType'] ?? null) === 'UNDEFINED'
                && ($dados['cycle'] ?? null) === 'MONTHLY'
                && ($dados['nextDueDate'] ?? null) === now()->addDays(5)->toDateString()
                && ($dados['value'] ?? null) === config('billing.plan.price');
        });
    }

    public function test_nenhuma_request_carrega_dado_de_cartao(): void
    {
        $this->comoOwner()->call('assinar');

        // PCI por construcao: nao existe campo de cartao em NENHUMA request.
        Http::assertNotSent(function ($request) {
            $corpo = json_encode($request->data());

            return str_contains($corpo, 'creditCard')
                || str_contains($corpo, 'ccv')
                || str_contains($corpo, 'holderName')
                || str_contains($corpo, 'expiryMonth');
        });
    }

    public function test_assinar_de_novo_reusa_ids_sem_duplicar(): void
    {
        $this->comoOwner()->call('assinar');
        $this->comoOwner()->call('assinar')->assertRedirect('https://sandbox.asaas.com/i/fatura123');

        // customers e subscriptions: UMA criacao cada (segunda chamada so busca a fatura).
        $this->assertSame(1, collect(Http::recorded())->filter(
            fn ($par) => str_ends_with(parse_url($par[0]->url(), PHP_URL_PATH), '/v3/customers') && $par[0]->method() === 'POST'
        )->count());
        $this->assertSame(1, collect(Http::recorded())->filter(
            fn ($par) => str_ends_with(parse_url($par[0]->url(), PHP_URL_PATH), '/v3/subscriptions') && $par[0]->method() === 'POST'
        )->count());
    }

    public function test_conta_sem_documento_nao_chama_o_asaas(): void
    {
        $this->account->forceFill(['document' => null])->save();

        $this->comoOwner()->call('assinar');

        Http::assertNothingSent();
        $this->assertNull($this->account->fresh()->asaas_customer_id);
    }

    public function test_cancelar_marca_canceled_e_nao_apaga_nada(): void
    {
        $this->comoOwner()->call('assinar');
        $usersAntes = User::count();

        $this->comoOwner()->call('cancelar');

        $conta = $this->account->fresh();
        $this->assertSame('canceled', $conta->subscription_status);
        $this->assertNotNull($conta->asaas_subscription_id); // id preservado (auditoria)
        $this->assertSame($usersAntes, User::count());       // nada apagado
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), '/v3/subscriptions/sub_teste_1'));
    }

    public function test_pagina_renderiza_para_owner_com_plano_e_status(): void
    {
        $this->actingAs($this->owner)->withSession(['tenancy.account_id' => $this->account->id])
            ->get(route('billing'))
            ->assertOk()
            ->assertSee('Assinatura')
            ->assertSee('Periodo de teste')
            ->assertSee(config('billing.plan.name'))
            ->assertSee('Nenhum dado de cartao passa pelo nosso sistema.', false);
    }

    public function test_rota_billing_e_owner_only(): void
    {
        $operador = User::create(['name' => 'Op', 'email' => 'op2@pag.local', 'password' => Hash::make('senha-forte-123')]);
        $operador->accounts()->attach($this->account->id, ['role' => 'operador']);

        $this->actingAs($operador)->withSession(['tenancy.account_id' => $this->account->id])
            ->get(route('billing'))->assertForbidden();
    }
}
