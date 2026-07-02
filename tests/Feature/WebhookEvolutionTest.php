<?php

namespace Tests\Feature;

use App\Jobs\ProcessIncomingWhatsappMessage;
use App\Models\IncomingMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Camada 1: o webhook so RECEBE e REGISTRA. Estes testes cobrem:
 *  - payload valido -> persiste / despacha o job
 *  - secret invalido/ausente -> bloqueado (401)
 *  - idempotencia -> mesmo evolution_message_id duas vezes -> 1 linha
 *
 * Fixture: tests/Fixtures/evolution_messages_upsert.json (representativo, "a confirmar").
 * Deve ser atualizado com um payload REAL capturado na prova do gate (Fase 6).
 */
class WebhookEvolutionTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'segredo-de-teste';

    protected function setUp(): void
    {
        parent::setUp();

        // Testes autossuficientes: nao dependem do segredo real do .env.
        config([
            'services.webhook.secret' => self::SECRET,
            'services.webhook.header' => 'X-Webhook-Secret',
        ]);

        // MT-0: a conta e resolvida pelo CANAL da instancia (como em producao, onde
        // o seeder cria conta+canal). Instancia sem canal = descartada por seguranca.
        $account = \App\Models\Account::create(['name' => 'T']);
        \App\Models\Channel::create(['account_id' => $account->id, 'instance' => 'fabio-pessoal', 'status' => 'connected']);
    }

    private function fixture(array $overrides = []): array
    {
        $payload = json_decode(
            file_get_contents(base_path('tests/Fixtures/evolution_messages_upsert.json')),
            true,
        );

        return array_replace_recursive($payload, $overrides);
    }

    public function test_payload_valido_persiste_a_mensagem(): void
    {
        $payload = $this->fixture();

        $response = $this->withHeaders(['X-Webhook-Secret' => self::SECRET])
            ->postJson('/webhook/evolution', $payload);

        $response->assertOk()->assertJson(['status' => 'queued']);

        $this->assertDatabaseCount('incoming_messages', 1);
        $this->assertDatabaseHas('incoming_messages', [
            'instance' => 'fabio-pessoal',
            'evolution_message_id' => '3AREALSTRUCTUREINBOUND01',
            'remote_jid' => '5541999990000@s.whatsapp.net',
            'from_me' => false,
            'type' => 'conversation',
            'text' => 'Qual o horario de funcionamento?',
        ]);

        // raw_payload preservado integralmente.
        $msg = IncomingMessage::first();
        $this->assertSame('messages.upsert', $msg->raw_payload['event']);
    }

    public function test_job_e_despachado_e_responde_rapido(): void
    {
        Queue::fake();

        $response = $this->withHeaders(['X-Webhook-Secret' => self::SECRET])
            ->postJson('/webhook/evolution', $this->fixture());

        $response->assertOk();
        Queue::assertPushed(ProcessIncomingWhatsappMessage::class, 1);
    }

    public function test_secret_invalido_e_bloqueado(): void
    {
        $response = $this->withHeaders(['X-Webhook-Secret' => 'errado'])
            ->postJson('/webhook/evolution', $this->fixture());

        $response->assertUnauthorized();
        $this->assertDatabaseCount('incoming_messages', 0);
    }

    public function test_secret_ausente_e_bloqueado(): void
    {
        $response = $this->postJson('/webhook/evolution', $this->fixture());

        $response->assertUnauthorized();
        $this->assertDatabaseCount('incoming_messages', 0);
    }

    public function test_idempotencia_mesmo_id_gera_uma_linha(): void
    {
        $payload = $this->fixture();

        $this->withHeaders(['X-Webhook-Secret' => self::SECRET])
            ->postJson('/webhook/evolution', $payload)->assertOk();

        // Re-entrega do mesmo evento (mesmo instance + id).
        $this->withHeaders(['X-Webhook-Secret' => self::SECRET])
            ->postJson('/webhook/evolution', $payload)->assertOk();

        $this->assertDatabaseCount('incoming_messages', 1);
    }

    public function test_evento_que_nao_e_mensagem_e_ignorado(): void
    {
        $payload = $this->fixture(['event' => 'presence.update']);

        $this->withHeaders(['X-Webhook-Secret' => self::SECRET])
            ->postJson('/webhook/evolution', $payload)->assertOk();

        $this->assertDatabaseCount('incoming_messages', 0);
    }
}
