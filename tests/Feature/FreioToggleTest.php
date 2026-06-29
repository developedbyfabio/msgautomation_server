<?php

namespace Tests\Feature;

use App\Livewire\Configuracoes;
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
use Livewire\Livewire;
use Tests\TestCase;

/**
 * S2 — toggles liga/desliga por freio. Desligado = aquele freio NAO bloqueia.
 * Ligado = usa o valor. Guardas estruturais (fromMe/idempotencia) seguem sempre ativos.
 */
class FreioToggleTest extends TestCase
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
    private function scaffold(array $over = []): array
    {
        $account = Account::create(['name' => 'Teste']);
        $channel = Channel::create(['account_id' => $account->id, 'instance' => 'fabio-pessoal', 'status' => 'connected']);
        AutoReplySetting::create(array_merge([
            'account_id' => $account->id, 'enabled' => true, 'reply_policy' => 'all',
            'window_start' => '08:00:00', 'window_end' => '20:00:00',
            'min_interval_seconds' => 0, 'per_minute_cap' => 100, 'per_day_cap' => 100,
            'contact_rate_seconds' => 0, 'delay_min_seconds' => 0, 'delay_max_seconds' => 0,
        ], $over));

        return [$account, $channel];
    }

    private function send(Account $a, Channel $c, ?int $ruleId = null): AutoReplyLog
    {
        $im = IncomingMessage::create([
            'account_id' => $a->id, 'channel_id' => $c->id, 'instance' => $c->instance,
            'evolution_message_id' => 'EVO' . uniqid(), 'remote_jid' => self::JID,
            'from_me' => false, 'type' => 'conversation', 'text' => 'oi',
            'raw_payload' => ['x' => 1], 'received_at' => now(),
        ]);

        return app(Sender::class)->send('auto', $c, self::JID, 'ola', $im->id, $ruleId);
    }

    public function test_janela_desligada_nao_bloqueia_fora_do_horario(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 29, 22, 0, 0, 'America/Sao_Paulo')); // fora de 08-20
        [$a, $c] = $this->scaffold(['window_enabled' => false]);

        $this->assertSame('sent', $this->send($a, $c)->status);
    }

    public function test_janela_ligada_bloqueia_fora_do_horario(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 29, 22, 0, 0, 'America/Sao_Paulo'));
        [$a, $c] = $this->scaffold(['window_enabled' => true]);

        $log = $this->send($a, $c);
        $this->assertSame('blocked', $log->status);
        $this->assertSame('fora_da_janela', $log->motivo);
    }

    public function test_teto_dia_desligado_nao_bloqueia(): void
    {
        [$a, $c] = $this->scaffold(['per_day_cap' => 1, 'per_day_enabled' => false]);

        $this->assertSame('sent', $this->send($a, $c)->status);
        $this->assertSame('sent', $this->send($a, $c)->status); // passaria do teto, mas desligado
    }

    public function test_intervalo_por_contato_desligado_nao_bloqueia(): void
    {
        [$a, $c] = $this->scaffold(['contact_rate_seconds' => 1800, 'contact_rate_enabled' => false]);
        // resposta recente ao contato
        AutoReplyLog::create([
            'account_id' => $a->id, 'channel_id' => $c->id, 'remote_jid' => self::JID,
            'mode' => 'auto', 'response_text' => 'x', 'status' => 'sent', 'sent_at' => now()->subSeconds(5),
        ]);

        $this->assertSame('sent', $this->send($a, $c)->status);
    }

    public function test_intervalo_minimo_desligado_nao_bloqueia(): void
    {
        [$a, $c] = $this->scaffold(['min_interval_seconds' => 9999, 'min_interval_enabled' => false]);

        $this->assertSame('sent', $this->send($a, $c)->status);
        $this->assertSame('sent', $this->send($a, $c)->status);
    }

    public function test_guarda_from_me_sempre_ativo_mesmo_com_toggles_off(): void
    {
        // Todos os toggles desligados nao afetam o guarda estrutural fromMe.
        [$a, $c] = $this->scaffold([
            'window_enabled' => false, 'min_interval_enabled' => false,
            'per_minute_enabled' => false, 'per_day_enabled' => false, 'contact_rate_enabled' => false,
        ]);
        $im = IncomingMessage::create([
            'account_id' => $a->id, 'channel_id' => $c->id, 'instance' => $c->instance,
            'evolution_message_id' => 'EVOFROM', 'remote_jid' => self::JID, 'from_me' => true,
            'type' => 'conversation', 'text' => 'oi', 'raw_payload' => ['x' => 1], 'received_at' => now(),
        ]);

        $log = app(Sender::class)->send('auto', $c, self::JID, 'ola', $im->id, null, true);
        $this->assertSame('blocked', $log->status);
        $this->assertSame('from_me', $log->motivo);
    }

    public function test_config_salva_toggles(): void
    {
        $account = Account::create(['name' => 'Teste']);
        AutoReplySetting::create(['account_id' => $account->id]);

        Livewire::test(Configuracoes::class)
            ->set('window_enabled', false)
            ->set('per_day_enabled', false)
            ->call('save');

        $this->assertDatabaseHas('auto_reply_settings', [
            'account_id' => $account->id, 'window_enabled' => false, 'per_day_enabled' => false,
            'min_interval_enabled' => true,
        ]);
    }
}
