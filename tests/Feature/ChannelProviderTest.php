<?php

namespace Tests\Feature;

use App\Channels\ChannelCapabilities;
use App\Channels\ChannelProvider;
use App\Channels\Evolution\EvolutionProvider;
use App\Channels\ProviderRegistry;
use App\Channels\UnknownChannelProviderException;
use App\Models\Account;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\Contact;
use App\Whatsapp\AutoReply\Sender;
use App\Whatsapp\IncomingMessageData;
use App\Whatsapp\Proactive\ProactiveGuard;
use App\Whatsapp\SentMessageData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CH-1 — contrato ChannelProvider + Evolution como primeiro provider. Provas:
 *  - registry resolve POR CANAL (default/backfill = evolution; desconhecido
 *    falha ALTO);
 *  - capacidades da Evolution declaradas corretas;
 *  - credenciais por canal com FALLBACK aditivo no env (vazio -> env;
 *    preenchido -> canal, cifrado em repouso);
 *  - webhook delega verify ao provider do canal (token identico ao MT-0);
 *  - freio 'canal_sem_proativa_livre' existe e NUNCA dispara com Evolution;
 *  - gancho de capacidade no Sender (mensagem livre) — no-op com Evolution;
 *  - DTO neutro (providerMessageId) persiste na coluna legada;
 *  - comandos de console operam via provider.
 * HTTP sempre mockado.
 */
class ChannelProviderTest extends TestCase
{
    use RefreshDatabase;

    private const JID = '5541999990000@s.whatsapp.net';

    private Account $account;
    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 7, 2, 10, 0, 0, 'America/Sao_Paulo'));
        Http::fake(['*' => Http::response(['key' => ['id' => 'PMID']], 201)]);

        $this->account = Account::create(['name' => 'T']);
        $this->channel = Channel::create([
            'account_id' => $this->account->id, 'instance' => 'fabio-pessoal',
            'webhook_token' => 'token-ch1', 'status' => 'connected',
        ]);
        AutoReplySetting::create([
            'account_id' => $this->account->id, 'enabled' => true, 'reply_policy' => 'all',
            'min_interval_seconds' => 0, 'per_minute_cap' => 100, 'per_day_cap' => 100,
            'contact_rate_seconds' => 0, 'contact_rate_enabled' => false,
            'delay_min_seconds' => 0, 'delay_max_seconds' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Dublê de provider com capacidades configuraveis (pra provar os ganchos). */
    private function fakeProvider(bool $proativaLivre, bool $mensagemLivre): ChannelProvider
    {
        return new class($proativaLivre, $mensagemLivre) implements ChannelProvider
        {
            public function __construct(private bool $proativa, private bool $livre)
            {
            }

            public function key(): string
            {
                return 'fake';
            }

            public function capabilities(): ChannelCapabilities
            {
                return new ChannelCapabilities(
                    grupos: false,
                    mensagemLivreForaDaJanela: $this->livre,
                    proativaLivre: $this->proativa,
                    qr: false,
                    template: true,
                );
            }

            public function sendText(Channel $channel, string $to, string $text, ?string $replyTo = null): SentMessageData
            {
                return new SentMessageData(providerMessageId: 'FAKE1', status: 201, raw: []);
            }

            public function sendImage(Channel $channel, string $to, string $filePath, string $mime, ?string $caption = null, ?string $replyTo = null): SentMessageData
            {
                return new SentMessageData(providerMessageId: 'FAKE-IMG', status: 201, raw: []);
            }

            public function verifyWebhook(Request $request, Channel $channel): bool
            {
                return true;
            }

            public function normalizeIncoming(array $payload): ?IncomingMessageData
            {
                return null;
            }

            public function connectionState(?Channel $channel = null): string
            {
                return 'connected';
            }
        };
    }

    // ---- registry -----------------------------------------------------------------

    public function test_registry_resolve_o_provider_pelo_canal_e_desconhecido_falha_alto(): void
    {
        $registry = app(ProviderRegistry::class);

        // Canal criado sem informar provider: backfill/default = evolution.
        $this->assertSame('evolution', $this->channel->fresh()->provider);
        $this->assertInstanceOf(EvolutionProvider::class, $registry->for($this->channel->fresh()));

        // Canal antigo com a coluna vazia tambem cai em evolution (retrocompat).
        $semColuna = new Channel(['instance' => 'x']);
        $this->assertInstanceOf(EvolutionProvider::class, $registry->for($semColuna));

        // Provider desconhecido: falha ALTO, nunca silencioso.
        $this->expectException(UnknownChannelProviderException::class);
        $registry->get('inexistente');
    }

    public function test_capacidades_da_evolution_declaradas(): void
    {
        $caps = app(EvolutionProvider::class)->capabilities();

        $this->assertTrue($caps->grupos);
        $this->assertTrue($caps->mensagemLivreForaDaJanela);
        $this->assertTrue($caps->proativaLivre);
        $this->assertTrue($caps->qr);
        $this->assertFalse($caps->template);
    }

    // ---- credenciais: canal cifrado com fallback ADITIVO no env ---------------------

    public function test_credenciais_do_canal_com_fallback_no_env(): void
    {
        config(['services.evolution.base_url' => 'http://env-host:8090', 'services.evolution.api_key' => 'chave-do-env']);
        $provider = app(EvolutionProvider::class);

        // Canal SEM credentials: cai no env (comportamento de hoje, MT-2 remove).
        $c = $provider->credentialsFor($this->channel->fresh());
        $this->assertSame('http://env-host:8090', $c['base_url']);
        $this->assertSame('chave-do-env', $c['apikey']);
        $this->assertSame('fabio-pessoal', $c['instance']);

        // Canal COM credentials: usa o registro (e envia com ELE, nao com o env).
        $this->channel->update(['credentials' => ['base_url' => 'http://canal-host:9999', 'apikey' => 'chave-do-canal']]);
        $c = $provider->credentialsFor($this->channel->fresh());
        $this->assertSame('http://canal-host:9999', $c['base_url']);
        $this->assertSame('chave-do-canal', $c['apikey']);

        $provider->sendText($this->channel->fresh(), self::JID, 'oi');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'canal-host:9999')
            && $r->header('apikey')[0] === 'chave-do-canal');

        // Cifrado em repouso: o valor NUNCA aparece em claro na coluna.
        $cru = (string) \Illuminate\Support\Facades\DB::table('channels')->where('id', $this->channel->id)->value('credentials');
        $this->assertStringNotContainsString('chave-do-canal', $cru);
    }

    // ---- webhook: delegacao ao provider do canal --------------------------------------

    public function test_webhook_por_token_delega_ao_provider_e_mantem_o_contrato_do_mt0(): void
    {
        $payload = ['event' => 'messages.upsert', 'instance' => 'fabio-pessoal', 'data' => [
            'key' => ['id' => 'CH1', 'fromMe' => false, 'remoteJid' => self::JID],
            'messageType' => 'conversation', 'message' => ['conversation' => 'oi'], 'messageTimestamp' => 1782699162,
        ]];

        $this->postJson('/webhook/evolution/token-ch1', $payload)->assertOk();
        $this->postJson('/webhook/evolution/token-errado', $payload)->assertUnauthorized();

        // A mensagem entrou pela coluna legada com o id do DTO NEUTRO.
        $this->assertDatabaseHas('incoming_messages', [
            'evolution_message_id' => 'CH1', 'account_id' => $this->account->id,
        ]);
    }

    public function test_dto_neutro_alimenta_o_dominio_identico(): void
    {
        $data = app(EvolutionProvider::class)->normalizeIncoming([
            'event' => 'MESSAGES_UPSERT', 'instance' => 'fabio-pessoal',
            'data' => [
                'key' => ['id' => 'ABC123', 'fromMe' => false, 'remoteJid' => self::JID],
                'pushName' => 'Cliente', 'messageType' => 'conversation',
                'message' => ['conversation' => 'oi'], 'messageTimestamp' => 1782699162,
            ],
        ]);

        $this->assertNotNull($data);
        $this->assertSame('ABC123', $data->providerMessageId); // nome NEUTRO (CH-D4)
        $this->assertSame('fabio-pessoal', $data->instance);
        $this->assertSame('oi', $data->text);
    }

    // ---- freio de capacidade: proativa ---------------------------------------------------

    public function test_freio_canal_sem_proativa_livre_nunca_dispara_com_evolution_e_bloqueia_provider_sem_capacidade(): void
    {
        AutoReplySetting::where('account_id', $this->account->id)->update(['proactive_enabled' => true]);
        $contato = Contact::create([
            'account_id' => $this->account->id, 'remote_jid' => self::JID,
            'auto_reply_mode' => 'on', 'proactive_opt_in' => true,
        ]);
        $guard = app(ProactiveGuard::class);

        // Evolution (proativaLivre = true): passa por TODOS os freios.
        $this->assertTrue($guard->allows($this->account->id, $contato->id, 'oi')->allowed);

        // Provider SEM proativa livre (Cloud API no CH-2): freio nomeado dispara.
        app(ProviderRegistry::class)->register('fake', $this->fakeProvider(proativaLivre: false, mensagemLivre: true));
        $this->channel->update(['provider' => 'fake']);

        $decision = $guard->allows($this->account->id, $contato->id, 'oi');
        $this->assertFalse($decision->allowed);
        $this->assertSame('canal_sem_proativa_livre', $decision->reason);
    }

    public function test_gancho_de_mensagem_livre_no_sender_no_op_com_evolution(): void
    {
        // Evolution: manual sai normal (gancho consulta capacidade e segue).
        $log = app(Sender::class)->send(mode: 'manual', channel: $this->channel->fresh(), jid: self::JID, text: 'oi');
        $this->assertSame('sent', $log->status);

        // Provider sem mensagem livre fora da janela: manual BLOQUEIA com motivo
        // claro (CH-2 troca o "assume fechada" pelo last_inbound_at real).
        app(ProviderRegistry::class)->register('fake', $this->fakeProvider(proativaLivre: true, mensagemLivre: false));
        $this->channel->update(['provider' => 'fake']);

        $log = app(Sender::class)->send(mode: 'manual', channel: $this->channel->fresh(), jid: self::JID, text: 'oi');
        $this->assertSame('blocked', $log->status);
        $this->assertSame('janela_24h', $log->motivo);
    }

    // ---- comandos de console via provider --------------------------------------------------

    public function test_comandos_de_console_operam_via_provider(): void
    {
        // evolution:status — estado via provider->api() (Http mockado).
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response(['instance' => ['state' => 'open']], 200)]);
        $this->artisan('evolution:status')->expectsOutputToContain('estado: open')->assertSuccessful();

        // whatsapp:send — envio manual via Sender -> provider do canal.
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response(['key' => ['id' => 'PMID']], 201)]);
        $this->artisan('whatsapp:send', ['jid' => self::JID, 'text' => 'oi manual'])->assertSuccessful();
        Http::assertSent(fn ($r) => $r['text'] === 'oi manual' && str_contains($r->url(), '/message/sendText/fabio-pessoal'));
    }

    // ---- estado de conexao normalizado ------------------------------------------------------

    public function test_connection_state_normalizado(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response(['instance' => ['state' => 'open']], 200)]);
        $this->assertSame('connected', app(EvolutionProvider::class)->connectionState($this->channel->fresh()));

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response(['instance' => ['state' => 'close']], 200)]);
        $this->assertSame('disconnected', app(EvolutionProvider::class)->connectionState($this->channel->fresh()));

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response([], 500)]);
        $this->assertSame('unknown', app(EvolutionProvider::class)->connectionState($this->channel->fresh()));
    }
}
