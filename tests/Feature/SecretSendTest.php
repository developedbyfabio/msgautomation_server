<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AutoReplyLog;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\IncomingMessage;
use App\Whatsapp\AutoReply\Sender;
use App\Whatsapp\Secrets\SecretVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * S4 — redacao em log + resolucao no envio. O valor decifrado vai SO na mensagem
 * enviada; o log guarda a redacao [senha: nome]. Senha ausente = falha controlada.
 * Sem envio real (HTTP mockado).
 */
class SecretSendTest extends TestCase
{
    use RefreshDatabase;

    private const JID = '5541999990000@s.whatsapp.net';
    private const SENHA = 'WifiSecreta!2026';

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
            'account_id' => $account->id, 'enabled' => true, 'reply_policy' => 'all',
            'window_start' => '08:00:00', 'window_end' => '20:00:00',
            'min_interval_seconds' => 0, 'per_minute_cap' => 100, 'per_day_cap' => 100,
            'contact_rate_seconds' => 0,
        ]);

        return [$account, $channel];
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

    public function test_envia_valor_decifrado_mas_loga_redacao(): void
    {
        [$account, $channel] = $this->scaffold();
        app(SecretVault::class)->put($account->id, 'wifi', self::SENHA);
        $im = $this->incoming($account, $channel);

        $texto = 'A senha do wifi e {senha:wifi}';
        $log = app(Sender::class)->send('auto', $channel, self::JID, $texto, $im->id);

        $this->assertSame('sent', $log->status);

        // A mensagem ENVIADA contem o valor decifrado.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/message/sendText/')
            && $r['text'] === 'A senha do wifi e ' . self::SENHA);

        // O LOG guarda a REDACAO, nunca o valor.
        $this->assertSame('A senha do wifi e [senha: wifi]', $log->fresh()->response_text);
        $this->assertStringNotContainsString(self::SENHA, (string) $log->fresh()->response_text);
        // Nenhuma linha de log no banco contem o valor.
        $this->assertSame(0, AutoReplyLog::where('response_text', 'like', '%' . self::SENHA . '%')->count());
    }

    public function test_senha_ausente_falha_controlada_sem_enviar(): void
    {
        [$account, $channel] = $this->scaffold();
        // NAO cadastra a senha 'wifi'.
        $im = $this->incoming($account, $channel);

        $log = app(Sender::class)->send('auto', $channel, self::JID, 'segue {senha:wifi}', $im->id);

        $this->assertSame('failed', $log->status);
        $this->assertSame('senha_ausente', $log->motivo);
        Http::assertNothingSent();
        // Log guarda a redacao, sem meia-resposta.
        $this->assertSame('segue [senha: wifi]', $log->fresh()->response_text);
    }

    public function test_resposta_sem_senha_funciona_sem_secrets_key(): void
    {
        // Regressao: SECRETS_KEY ausente NAO pode quebrar auto-resposta sem senha.
        // (O cofre e preguicoso: so exige a chave ao cifrar/decifrar de verdade.)
        config(['secrets.key' => '']);
        [$account, $channel] = $this->scaffold();
        $im = $this->incoming($account, $channel);

        $log = app(Sender::class)->send('auto', $channel, self::JID, 'A senha do wifi e v6G8+6Dw', $im->id);

        $this->assertSame('sent', $log->status);
        Http::assertSent(fn ($r) => $r['text'] === 'A senha do wifi e v6G8+6Dw');
    }

    public function test_resposta_sem_senha_inalterada(): void
    {
        [$account, $channel] = $this->scaffold();
        $im = $this->incoming($account, $channel);

        $log = app(Sender::class)->send('auto', $channel, self::JID, 'ola tudo bem', $im->id);

        $this->assertSame('sent', $log->status);
        $this->assertSame('ola tudo bem', $log->fresh()->response_text);
        Http::assertSent(fn ($r) => $r['text'] === 'ola tudo bem');
    }
}
