<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AutoReplyLog;
use App\Models\AutoReplyRule;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\IncomingMessage;
use App\Whatsapp\AutoReply\Sender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * S2 — frequencia por regra (cooldown). Substitui o rate-por-contato global PARA a
 * regra; tetos de volume seguem valendo. Sem envio real (HTTP mockado).
 */
class RuleCooldownTest extends TestCase
{
    use RefreshDatabase;

    private const JID = '5541999990000@s.whatsapp.net';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 6, 29, 10, 0, 0, 'America/Sao_Paulo'));
        Http::fake(['*' => Http::response(['key' => ['id' => 'PMID']], 201)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0:Account,1:Channel} */
    private function scaffold(): array
    {
        $account = Account::create(['name' => 'Teste']);
        $channel = Channel::create(['account_id' => $account->id, 'instance' => 'fabio-pessoal', 'status' => 'connected']);
        AutoReplySetting::create([
            'account_id' => $account->id,
            'enabled' => true,
            'reply_policy' => 'all',
            'window_start' => '08:00:00',
            'window_end' => '20:00:00',
            'min_interval_seconds' => 0,
            'per_minute_cap' => 100,
            'per_day_cap' => 100,
            'contact_rate_seconds' => 0, // global rate "desligado" -> isola o cooldown da regra
            'skip_groups' => true,
            'delay_min_seconds' => 0,
            'delay_max_seconds' => 0,
        ]);

        return [$account, $channel];
    }

    private function rule(Account $account, string $cooldownMode, ?int $minutes = null): AutoReplyRule
    {
        return AutoReplyRule::create([
            'account_id' => $account->id,
            'match_type' => 'contains',
            'match_value' => 'oi',
            'response_text' => 'ola',
            'enabled' => true,
            'priority' => 0,
            'cooldown_mode' => $cooldownMode,
            'cooldown_minutes' => $minutes,
        ]);
    }

    private function incoming(Account $a, Channel $c): IncomingMessage
    {
        return IncomingMessage::create([
            'account_id' => $a->id, 'channel_id' => $c->id, 'instance' => $c->instance,
            'evolution_message_id' => 'EVO' . uniqid(), 'remote_jid' => self::JID,
            'from_me' => false, 'type' => 'conversation', 'text' => 'oi',
            'raw_payload' => ['x' => 1], 'received_at' => now(),
        ]);
    }

    private function send(Channel $channel, AutoReplyRule $rule): AutoReplyLog
    {
        $im = $this->incoming($channel->account, $channel);

        return app(Sender::class)->send('auto', $channel, self::JID, 'ola', $im->id, $rule->id);
    }

    public function test_sempre_responde_toda_vez(): void
    {
        [$account, $channel] = $this->scaffold();
        $rule = $this->rule($account, 'sempre');

        $this->assertSame('sent', $this->send($channel, $rule)->status);
        $this->assertSame('sent', $this->send($channel, $rule)->status);
        Http::assertSentCount(2);
    }

    public function test_1x_dia_bloqueia_segunda_no_mesmo_dia(): void
    {
        [$account, $channel] = $this->scaffold();
        $rule = $this->rule($account, '1x_dia');

        $a = $this->send($channel, $rule);
        $b = $this->send($channel, $rule);

        $this->assertSame('sent', $a->status);
        $this->assertSame('blocked', $b->status);
        $this->assertSame('cooldown_dia', $b->motivo);
        Http::assertSentCount(1);
    }

    public function test_1x_dia_libera_no_dia_seguinte(): void
    {
        [$account, $channel] = $this->scaffold();
        $rule = $this->rule($account, '1x_dia');

        $this->assertSame('sent', $this->send($channel, $rule)->status);

        // Vira o dia (SP): a regra volta a poder responder.
        Carbon::setTestNow(Carbon::create(2026, 6, 30, 10, 0, 0, 'America/Sao_Paulo'));
        $this->assertSame('sent', $this->send($channel, $rule)->status);
        Http::assertSentCount(2);
    }

    public function test_cada_n_bloqueia_dentro_da_janela_e_libera_depois(): void
    {
        [$account, $channel] = $this->scaffold();
        $rule = $this->rule($account, 'cada_n', 60); // 60 min

        $a = $this->send($channel, $rule);
        $this->assertSame('sent', $a->status);

        // 30 min depois -> ainda em cooldown.
        Carbon::setTestNow(now()->addMinutes(30));
        $b = $this->send($channel, $rule);
        $this->assertSame('blocked', $b->status);
        $this->assertSame('cooldown', $b->motivo);

        // 61 min depois do primeiro -> libera.
        Carbon::setTestNow(now()->addMinutes(31));
        $c = $this->send($channel, $rule);
        $this->assertSame('sent', $c->status);
        Http::assertSentCount(2);
    }

    public function test_global_preserva_rate_por_contato(): void
    {
        [$account, $channel] = $this->scaffold();
        // contact_rate global ligado + regra com cooldown 'global' (default).
        AutoReplySetting::where('account_id', $account->id)->update(['contact_rate_seconds' => 1800]);
        $rule = $this->rule($account, 'global');

        $a = $this->send($channel, $rule);
        $b = $this->send($channel, $rule);

        $this->assertSame('sent', $a->status);
        $this->assertSame('blocked', $b->status);
        $this->assertSame('rate_contato', $b->motivo);
    }

    public function test_cooldown_nao_burla_teto_de_volume(): void
    {
        [$account, $channel] = $this->scaffold();
        // 'sempre' na regra, mas teto diario = 1 -> o piso de volume ainda barra.
        AutoReplySetting::where('account_id', $account->id)->update(['per_day_cap' => 1]);
        $rule = $this->rule($account, 'sempre');

        $this->assertSame('sent', $this->send($channel, $rule)->status);
        $b = $this->send($channel, $rule);
        $this->assertSame('blocked', $b->status);
        $this->assertSame('teto_dia', $b->motivo);
    }
}
