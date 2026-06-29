<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AutoReplyLog;
use App\Models\AutoReplyRule;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Camada 2 Fatia 3: recebimento -> agenda -> match -> auto-resposta.
 * Via webhook real (QUEUE=sync nos testes). HTTP da Evolution mockado — SEM envio real.
 * O kill switch e flipado SO no ambiente de teste (sqlite), nunca na config real.
 */
class AutoReplyWireTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'segredo-de-teste';
    private const JID = '5541999990000@s.whatsapp.net';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 6, 29, 10, 0, 0, 'America/Sao_Paulo'));
        Http::preventStrayRequests();
        config(['services.webhook.secret' => self::SECRET, 'services.webhook.header' => 'X-Webhook-Secret']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function scaffold(bool $enabled, string $policy): array
    {
        $account = Account::create(['name' => 'Teste']);
        $channel = Channel::create(['account_id' => $account->id, 'instance' => 'fabio-pessoal', 'status' => 'connected']);
        AutoReplySetting::create([
            'account_id' => $account->id,
            'enabled' => $enabled,
            'reply_policy' => $policy,
            'window_start' => '08:00:00',
            'window_end' => '20:00:00',
            'min_interval_seconds' => 0,
            'per_minute_cap' => 10,
            'per_day_cap' => 40,
            'contact_rate_seconds' => 1800,
            'delay_min_seconds' => 0,
            'delay_max_seconds' => 0,
            'skip_groups' => true,
        ]);

        return [$account, $channel];
    }

    private function rule(Account $account, string $value = 'horario'): AutoReplyRule
    {
        return AutoReplyRule::create([
            'account_id' => $account->id,
            'match_type' => 'contains',
            'match_value' => $value,
            'response_text' => 'Atendo das 8h as 18h',
            'enabled' => true,
            'priority' => 0,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'event' => 'messages.upsert',
            'instance' => 'fabio-pessoal',
            'data' => [
                'key' => ['id' => 'EVO' . uniqid(), 'fromMe' => false, 'remoteJid' => self::JID],
                'pushName' => 'Cliente Teste',
                'messageType' => 'conversation',
                'message' => ['conversation' => 'Qual o horario?'],
                'messageTimestamp' => 1782699162,
            ],
        ], $overrides);
    }

    private function postWebhook(array $overrides = []): void
    {
        $this->withHeaders(['X-Webhook-Secret' => self::SECRET])
            ->postJson('/webhook/evolution', $this->payload($overrides))
            ->assertOk();
    }

    private function fakeOk(): void
    {
        Http::fake(['*' => Http::response(['key' => ['id' => 'PROVIDERMSG123']], 201)]);
    }

    public function test_responde_quando_aprovado_e_regra_casa_com_kill_on(): void
    {
        $this->fakeOk();
        [$account] = $this->scaffold(enabled: true, policy: 'allowlist');
        $this->rule($account);
        Contact::create(['account_id' => $account->id, 'remote_jid' => self::JID, 'auto_reply_mode' => 'on']);

        $this->postWebhook();

        Http::assertSentCount(1);
        $this->assertDatabaseHas('auto_reply_logs', ['mode' => 'auto', 'status' => 'sent', 'remote_jid' => self::JID]);
    }

    public function test_allowlist_contato_default_silencia(): void
    {
        $this->fakeOk();
        [$account] = $this->scaffold(enabled: true, policy: 'allowlist');
        $this->rule($account);
        // Sem contato pre-criado -> auto-populado como 'default' -> nao responde.

        $this->postWebhook();

        Http::assertSentCount(0);
        $this->assertDatabaseCount('auto_reply_logs', 0);
        $this->assertDatabaseHas('contacts', ['remote_jid' => self::JID, 'auto_reply_mode' => 'default', 'push_name' => 'Cliente Teste']);
    }

    public function test_policy_all_responde_contato_default(): void
    {
        $this->fakeOk();
        [$account] = $this->scaffold(enabled: true, policy: 'all');
        $this->rule($account);

        $this->postWebhook();

        Http::assertSentCount(1);
    }

    public function test_policy_all_silencia_contato_off(): void
    {
        $this->fakeOk();
        [$account] = $this->scaffold(enabled: true, policy: 'all');
        $this->rule($account);
        Contact::create(['account_id' => $account->id, 'remote_jid' => self::JID, 'auto_reply_mode' => 'off']);

        $this->postWebhook();

        Http::assertSentCount(0);
    }

    public function test_sem_regra_silencia(): void
    {
        $this->fakeOk();
        [$account] = $this->scaffold(enabled: true, policy: 'all');
        // sem regra cadastrada

        $this->postWebhook();

        Http::assertSentCount(0);
    }

    public function test_from_me_ignorado_e_nao_popula_contato(): void
    {
        $this->fakeOk();
        [$account] = $this->scaffold(enabled: true, policy: 'all');
        $this->rule($account);

        $this->postWebhook(['data' => ['key' => ['fromMe' => true]]]);

        Http::assertSentCount(0);
        $this->assertDatabaseCount('contacts', 0);
    }

    public function test_grupo_pulado(): void
    {
        $this->fakeOk();
        [$account] = $this->scaffold(enabled: true, policy: 'all');
        $this->rule($account);

        $this->postWebhook(['data' => ['key' => ['remoteJid' => '123456789@g.us']]]);

        Http::assertSentCount(0);
        $this->assertDatabaseMissing('contacts', ['remote_jid' => '123456789@g.us']);
    }

    public function test_kill_switch_off_silencia_mesmo_aprovado(): void
    {
        $this->fakeOk();
        [$account] = $this->scaffold(enabled: false, policy: 'all'); // kill OFF (estado real)
        $this->rule($account);
        Contact::create(['account_id' => $account->id, 'remote_jid' => self::JID, 'auto_reply_mode' => 'on']);

        $this->postWebhook();

        // Dispara (aprovado + regra casa), mas o Sender barra no kill switch -> log blocked.
        Http::assertSentCount(0);
        $this->assertDatabaseHas('auto_reply_logs', ['status' => 'blocked', 'motivo' => 'kill_switch']);
    }

    public function test_contato_auto_populado_no_recebimento(): void
    {
        $this->fakeOk();
        [$account] = $this->scaffold(enabled: true, policy: 'allowlist');

        $this->postWebhook(['data' => ['pushName' => 'Joao da Silva']]);

        $this->assertDatabaseHas('contacts', [
            'account_id' => $account->id,
            'remote_jid' => self::JID,
            'push_name' => 'Joao da Silva',
            'auto_reply_mode' => 'default',
        ]);
    }

    public function test_idempotencia_no_recebimento_nao_reenvia(): void
    {
        $this->fakeOk();
        [$account] = $this->scaffold(enabled: true, policy: 'all');
        $this->rule($account);
        $payload = $this->payload(['data' => ['key' => ['id' => 'FIXO-001']]]);

        $this->withHeaders(['X-Webhook-Secret' => self::SECRET])->postJson('/webhook/evolution', $payload)->assertOk();
        $this->withHeaders(['X-Webhook-Secret' => self::SECRET])->postJson('/webhook/evolution', $payload)->assertOk();

        $this->assertDatabaseCount('incoming_messages', 1);
        Http::assertSentCount(1);
    }
}
