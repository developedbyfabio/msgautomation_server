<?php

namespace Tests\Feature;

use App\Jobs\ProcessAsaasWebhookEvent;
use App\Models\Account;
use App\Models\BillingWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fatia 26 — webhook de cobranca do Asaas. As TRES invariantes inegociaveis:
 * autenticidade (asaas-access-token, 401 sem/errado), idempotencia (dedup por
 * event id: reentrega "at least once" = no-op) e rapido/assincrono (200 +
 * job; o processamento pesado NUNCA e inline). E a maquina de estados aplicada
 * pelo job, vinculada pelo atributo `subscription` do payment (doc oficial:
 * o webhook e de COBRANCA, nao de assinatura).
 */
class AsaasWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-webhook-de-teste-0123456789abcdef'; // do phpunit.xml (fake)

    private function payload(string $eventId, string $evento, string $subscription = 'sub_00001', array $payment = []): array
    {
        // Formato real do webhook de cobranca (doc oficial): id do EVENTO +
        // event + objeto payment completo (com `subscription` quando a cobranca
        // pertence a uma assinatura).
        return [
            'id' => $eventId,
            'event' => $evento,
            'dateCreated' => '2026-07-06 12:00:00',
            'payment' => array_merge([
                'id' => 'pay_000000000001',
                'customer' => 'cus_000000000001',
                'subscription' => $subscription,
                'value' => 149.90,
                'status' => 'RECEIVED',
                'billingType' => 'PIX',
                'dueDate' => '2026-07-06',
            ], $payment),
        ];
    }

    private function contaComAssinatura(string $subscription = 'sub_00001', string $status = 'trial'): Account
    {
        $conta = Account::create(['name' => 'Cliente ' . $subscription]);
        $conta->forceFill([
            'subscription_status' => $status,
            'trial_ends_at' => now()->addDays(2),
            'asaas_customer_id' => 'cus_000000000001',
            'asaas_subscription_id' => $subscription,
        ])->save();

        return $conta;
    }

    // ---- invariante 1: autenticidade ---------------------------------------------

    public function test_sem_token_401_e_nada_e_processado(): void
    {
        Queue::fake();
        $conta = $this->contaComAssinatura();

        $this->postJson('/webhook/asaas', $this->payload('evt_1', 'PAYMENT_RECEIVED'))
            ->assertStatus(401);

        $this->assertSame(0, BillingWebhookEvent::count());
        $this->assertSame('trial', $conta->fresh()->subscription_status);
        Queue::assertNothingPushed();
    }

    public function test_token_errado_401(): void
    {
        Queue::fake();

        $this->postJson('/webhook/asaas', $this->payload('evt_1', 'PAYMENT_RECEIVED'), [
            'asaas-access-token' => 'token-forjado-pelo-atacante-1234567890',
        ])->assertStatus(401);

        $this->assertSame(0, BillingWebhookEvent::count());
        Queue::assertNothingPushed();
    }

    // ---- invariantes 2 e 3: dedup + 200 rapido com job ----------------------------

    public function test_token_certo_200_registra_e_enfileira_o_job(): void
    {
        Queue::fake(); // prova que o endpoint SO enfileira (processamento no job)
        $this->contaComAssinatura();

        $this->postJson('/webhook/asaas', $this->payload('evt_1', 'PAYMENT_RECEIVED'), [
            'asaas-access-token' => self::TOKEN,
        ])->assertOk();

        $this->assertSame(1, BillingWebhookEvent::count());
        $evento = BillingWebhookEvent::first();
        $this->assertSame('evt_1', $evento->event_id);
        $this->assertSame('sub_00001', $evento->subscription_id);
        Queue::assertPushed(ProcessAsaasWebhookEvent::class, 1);
    }

    public function test_mesmo_event_id_duas_vezes_e_no_op(): void
    {
        Queue::fake();
        $this->contaComAssinatura();

        foreach (range(1, 2) as $i) {
            $this->postJson('/webhook/asaas', $this->payload('evt_repetido', 'PAYMENT_RECEIVED'), [
                'asaas-access-token' => self::TOKEN,
            ])->assertOk(); // retry do Asaas: 200 sempre (senao ele re-tenta pra sempre)
        }

        $this->assertSame(1, BillingWebhookEvent::count()); // UMA linha
        Queue::assertPushed(ProcessAsaasWebhookEvent::class, 1); // UM job
    }

    // ---- maquina de estados via job (fila sync do phpunit) ------------------------

    public function test_payment_confirmed_ativa_a_conta(): void
    {
        $conta = $this->contaComAssinatura(status: 'trial');

        $this->postJson('/webhook/asaas', $this->payload('evt_c1', 'PAYMENT_CONFIRMED'), [
            'asaas-access-token' => self::TOKEN,
        ])->assertOk();

        $this->assertSame('active', $conta->fresh()->subscription_status);
        $this->assertSame('processed', BillingWebhookEvent::first()->status);
    }

    public function test_payment_overdue_marca_overdue_com_marco(): void
    {
        $conta = $this->contaComAssinatura(status: 'active');

        $this->postJson('/webhook/asaas', $this->payload('evt_o1', 'PAYMENT_OVERDUE'), [
            'asaas-access-token' => self::TOKEN,
        ])->assertOk();

        $conta->refresh();
        $this->assertSame('overdue', $conta->subscription_status);
        $this->assertNotNull($conta->overdue_since);
    }

    public function test_reativacao_pagamento_apos_suspensao_volta_pra_active(): void
    {
        $conta = $this->contaComAssinatura(status: 'suspended');
        $conta->forceFill(['suspended_at' => now()->subDay(), 'overdue_since' => now()->subDays(6)])->save();

        $this->postJson('/webhook/asaas', $this->payload('evt_r1', 'PAYMENT_RECEIVED'), [
            'asaas-access-token' => self::TOKEN,
        ])->assertOk();

        $conta->refresh();
        $this->assertSame('active', $conta->subscription_status);
        $this->assertNull($conta->suspended_at);   // marcos limpos
        $this->assertNull($conta->overdue_since);
    }

    public function test_vinculo_pelo_subscription_muda_so_a_conta_certa(): void
    {
        $a = $this->contaComAssinatura('sub_conta_A', 'trial');
        $b = $this->contaComAssinatura('sub_conta_B', 'trial');

        $this->postJson('/webhook/asaas', $this->payload('evt_ab', 'PAYMENT_CONFIRMED', 'sub_conta_A'), [
            'asaas-access-token' => self::TOKEN,
        ])->assertOk();

        $this->assertSame('active', $a->fresh()->subscription_status); // A ativou
        $this->assertSame('trial', $b->fresh()->subscription_status);  // B intacta
    }

    public function test_evento_de_assinatura_desconhecida_e_ignorado_sem_tocar_conta(): void
    {
        $legacy = Account::create(['name' => 'Legacy']); // sem asaas ids, 'active'

        $this->postJson('/webhook/asaas', $this->payload('evt_x', 'PAYMENT_CONFIRMED', 'sub_de_ninguem', ['customer' => 'cus_de_ninguem']), [
            'asaas-access-token' => self::TOKEN,
        ])->assertOk();

        $this->assertSame('ignored', BillingWebhookEvent::first()->status);
        $this->assertSame('active', $legacy->fresh()->subscription_status);
    }

    public function test_reprocessar_o_mesmo_evento_no_job_e_idempotente(): void
    {
        $conta = $this->contaComAssinatura(status: 'trial');

        $this->postJson('/webhook/asaas', $this->payload('evt_i1', 'PAYMENT_CONFIRMED'), [
            'asaas-access-token' => self::TOKEN,
        ])->assertOk();
        $this->assertSame('active', $conta->fresh()->subscription_status);

        // Retry do PROPRIO job (alem do dedup do endpoint): processed_at trava.
        $eventosAntes = \App\Models\SystemEvent::withoutAccountScope()->count();
        (new ProcessAsaasWebhookEvent(BillingWebhookEvent::first()->id))->handle(app(\App\Billing\BillingState::class));

        $this->assertSame('active', $conta->fresh()->subscription_status);
        $this->assertSame($eventosAntes, \App\Models\SystemEvent::withoutAccountScope()->count()); // sem efeito duplicado
    }

    public function test_payment_deleted_nao_muda_estado(): void
    {
        $conta = $this->contaComAssinatura(status: 'active');

        $this->postJson('/webhook/asaas', $this->payload('evt_d1', 'PAYMENT_DELETED'), [
            'asaas-access-token' => self::TOKEN,
        ])->assertOk();

        $this->assertSame('active', $conta->fresh()->subscription_status);
    }

    public function test_canceled_e_terminal_para_evento_de_cobranca(): void
    {
        $conta = $this->contaComAssinatura(status: 'canceled');
        $conta->forceFill(['suspended_at' => now()])->save();

        $this->postJson('/webhook/asaas', $this->payload('evt_t1', 'PAYMENT_RECEIVED'), [
            'asaas-access-token' => self::TOKEN,
        ])->assertOk();

        // Pagamento atrasado pos-cancelamento NAO "descancela" (so novo checkout).
        $this->assertSame('canceled', $conta->fresh()->subscription_status);
    }
}
