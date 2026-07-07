<?php

namespace Tests\Feature;

use App\Channels\ProviderRegistry;
use App\Jobs\SendServerAlert;
use App\Mail\ServersAlertFallback;
use App\Models\Account;
use App\Models\Channel;
use App\Models\SystemEvent;
use App\Models\User;
use App\Servers\AlertContact;
use App\Servers\AlertRule;
use App\Servers\AlertRuleDefaults;
use App\Servers\Incident;
use App\Servers\IncidentManager;
use App\Servers\MetricsBuffer;
use App\Servers\Server;
use App\Servers\ServerEvaluator;
use App\Whatsapp\Exceptions\WhatsappSendException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Servidores S3 — canal WhatsApp atras do flag (testes espelho do S2). Com o
 * flag ON e transicoes reais: Http::assertSent confirma destinatario+payload
 * (o inverso do assertNothingSent). Cobre roteamento por severidade,
 * agrupamento de tempestade, re-notificacao so de critical nao-reconhecido,
 * resolucao notifica, falha -> retry/fallback e-mail/SystemEvent, e flag OFF =
 * nada enviado (S2 intacto).
 */
class ServersCanalTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('servers.notifications_enabled', true); // canal LIGADO nos testes de envio

        $this->account = Account::create(['name' => 'Empresa']);
        Channel::withoutAccountScope()->create([
            'account_id' => $this->account->id,
            'instance' => 'inst-a',
            'provider' => 'evolution',
            'credentials' => ['base_url' => 'https://evo.test', 'apikey' => 'k'],
            'webhook_token' => 'tok-a',
            'status' => 'connected',
        ]);
        $this->server = Server::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'name' => 'srv-a', 'os' => 'linux',
            'grupo' => 'producao', 'last_seen_at' => now(),
        ]);
        AlertRuleDefaults::ensureFor($this->account->id);
    }

    private function contato(array $extra = []): AlertContact
    {
        return AlertContact::withoutAccountScope()->create(array_merge([
            'account_id' => $this->account->id,
            'name' => 'Fabio', 'phone' => '5511999990000', 'min_level' => 'warning', 'enabled' => true,
        ], $extra));
    }

    /** Abre um incidente critico de CPU via avaliacao real. */
    private function abrirCpuCritical(?Server $server = null): void
    {
        $server ??= $this->server;
        // Servidor ocupado ainda REPORTA (last_seen_at fresco): isola do watchdog.
        $server->forceFill(['last_seen_at' => now()])->save();
        $buffer = app(MetricsBuffer::class);
        foreach ([150, 120, 90, 60, 30, 0] as $age) {
            $buffer->push($server->id, ['received_at' => now()->getTimestamp() - $age, 'cpu_pct' => 97.0, 'mem_pct' => 10.0, 'disks' => [['mount' => '/', 'pct' => 20.0]]]);
        }
        app(ServerEvaluator::class)->evaluate($server->fresh());
    }

    private function evaluateCommand(): void
    {
        $this->artisan('servers:evaluate')->assertSuccessful();
    }

    // ---- envio real (Http::assertSent) ----------------------------------------

    public function test_flag_on_transicao_envia_para_o_destinatario_com_payload_correto(): void
    {
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm1']], 200)]);
        $this->contato();

        $this->abrirCpuCritical();
        $this->evaluateCommand(); // avalia + despacha o job (sync) que envia

        Http::assertSent(function ($req) {
            return str_contains($req->url(), '/message/sendText/inst-a')
                && $req['number'] === '5511999990000'
                && str_contains($req['text'], 'srv-a')
                && str_contains($req['text'], 'CPU');
        });

        // Idempotencia: rodar de novo NAO re-envia (ja notificado).
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm2']], 200)]);
        $this->evaluateCommand();
        Http::assertNothingSent();
    }

    public function test_roteamento_por_severidade(): void
    {
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        $soCritical = $this->contato(['name' => 'OnCall', 'phone' => '5511888880000', 'min_level' => 'critical']);
        $tudo = $this->contato(['name' => 'Geral', 'phone' => '5511777770000', 'min_level' => 'warning']);

        // Abre um WARNING (cpu 90 por 330s).
        $buffer = app(MetricsBuffer::class);
        foreach ([330, 300, 240, 180, 120, 60, 0] as $age) {
            $buffer->push($this->server->id, ['received_at' => now()->getTimestamp() - $age, 'cpu_pct' => 90.0, 'mem_pct' => 10.0, 'disks' => [['mount' => '/', 'pct' => 20.0]]]);
        }
        app(ServerEvaluator::class)->evaluate($this->server->fresh());
        $this->assertSame('warning', Incident::withoutAccountScope()->sole()->level);

        $this->evaluateCommand();

        // Warning: quem so quer critical NAO recebe; quem quer tudo recebe.
        Http::assertSent(fn ($req) => $req['number'] === $tudo->phone);
        Http::assertNotSent(fn ($req) => $req['number'] === $soCritical->phone);
    }

    public function test_agrupamento_de_tempestade_uma_mensagem_por_destinatario(): void
    {
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        $this->contato(); // recebe de todos

        // Rack caindo: 3 servidores mudos -> 3 incidentes watchdog no MESMO tick.
        $s2 = Server::withoutAccountScope()->create(['account_id' => $this->account->id, 'name' => 'srv-b', 'os' => 'linux', 'last_seen_at' => now()->subSeconds(400)]);
        $s3 = Server::withoutAccountScope()->create(['account_id' => $this->account->id, 'name' => 'srv-c', 'os' => 'linux', 'last_seen_at' => now()->subSeconds(400)]);
        $this->server->forceFill(['last_seen_at' => now()->subSeconds(400)])->save();

        $this->evaluateCommand();

        $this->assertSame(3, Incident::withoutAccountScope()->where('metric', 'watchdog')->count());
        // UMA mensagem (agrupada) para o destinatario, com os 3 servidores.
        Http::assertSentCount(1);
        Http::assertSent(fn ($req) => str_contains($req['text'], 'srv-a')
            && str_contains($req['text'], 'srv-b') && str_contains($req['text'], 'srv-c'));
    }

    public function test_resolucao_tambem_notifica(): void
    {
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        $this->contato();

        $this->abrirCpuCritical();
        $this->evaluateCommand(); // notifica abertura
        Http::assertSent(fn ($req) => str_contains($req['text'], 'srv-a')); // texto por-incidente

        // Normaliza -> resolve -> notifica resolucao.
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm2']], 200)]);
        app(MetricsBuffer::class)->forget($this->server->id);
        $buffer = app(MetricsBuffer::class);
        foreach ([330, 270, 210, 150, 90, 30, 0] as $age) {
            $buffer->push($this->server->id, ['received_at' => now()->getTimestamp() - $age, 'cpu_pct' => 5.0, 'mem_pct' => 10.0, 'disks' => [['mount' => '/', 'pct' => 20.0]]]);
        }
        app(ServerEvaluator::class)->evaluate($this->server->fresh());
        $this->assertSame('resolved', Incident::withoutAccountScope()->sole()->status);

        $this->evaluateCommand();
        Http::assertSent(fn ($req) => str_contains($req['text'], 'resolvido') || str_contains($req['text'], '✅'));
    }

    // ---- re-notificacao: so critical nao-reconhecido ---------------------------

    public function test_critical_nao_reconhecido_renotifica_apos_cooldown(): void
    {
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        // Cadencia de re-aviso curta para o teste (critical a cada 60s).
        AlertRule::withoutAccountScope()->where('account_id', $this->account->id)->whereNull('server_id')->where('metric', 'cpu')->update(['critical_repeat_s' => 60]);
        $this->contato();

        $this->abrirCpuCritical();
        $this->evaluateCommand(); // 1a notificacao
        Http::assertSentCount(1);

        // Antes do cooldown: nao repete.
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        $this->abrirCpuCritical(); // condicao persiste
        $this->evaluateCommand();
        Http::assertNothingSent();

        // Passado o cooldown: re-notifica (so porque e CRITICAL e nao-reconhecido).
        $this->travel(120)->seconds();
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        $this->abrirCpuCritical();
        $this->evaluateCommand();
        Http::assertSentCount(1);
    }

    public function test_warning_nunca_renotifica(): void
    {
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        // warning_repeat_s NULL (default) = avisar 1 vez -> warning nunca repete.
        $this->contato();

        $buffer = app(MetricsBuffer::class);
        // Servidor reportando (last_seen fresco a cada rodada): isola do watchdog.
        $warn = function () use ($buffer) {
            $this->server->forceFill(['last_seen_at' => now()])->save();
            foreach ([330, 300, 240, 180, 120, 60, 0] as $age) {
                $buffer->push($this->server->id, ['received_at' => now()->getTimestamp() - $age, 'cpu_pct' => 90.0, 'mem_pct' => 10.0, 'disks' => [['mount' => '/', 'pct' => 20.0]]]);
            }
        };
        $warn();
        app(ServerEvaluator::class)->evaluate($this->server->fresh());
        $this->evaluateCommand();
        Http::assertSentCount(1);

        $this->travel(3600)->seconds(); // muito alem de qualquer cooldown
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        $warn();
        app(ServerEvaluator::class)->evaluate($this->server->fresh());
        $this->evaluateCommand();
        Http::assertNothingSent(); // warning nao repete
    }

    public function test_reconhecido_nao_renotifica_mesmo_critical(): void
    {
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        // Re-aviso critical a cada 60s: o ack deve silenciar mesmo com o intervalo vencido.
        AlertRule::withoutAccountScope()->where('account_id', $this->account->id)->whereNull('server_id')->where('metric', 'cpu')->update(['critical_repeat_s' => 60]);
        $this->contato();

        $this->abrirCpuCritical();
        $this->evaluateCommand();
        $inc = Incident::withoutAccountScope()->sole();

        // Dono reconhece; passa o intervalo de re-aviso; a condicao persiste.
        app(IncidentManager::class)->acknowledge($inc, $this->donoId());
        $this->travel(120)->seconds();
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        $this->abrirCpuCritical();
        $this->evaluateCommand();

        Http::assertNothingSent(); // ack silencia a repeticao
    }

    // ---- falha de entrega: retry + fallback e-mail + SystemEvent ---------------

    public function test_falha_de_entrega_relanca_para_retry(): void
    {
        Http::fake(['evo.test/*' => Http::response(['error' => 'down'], 500)]);
        $this->contato();
        $this->abrirCpuCritical();

        // Job sincrono: a falha de transporte relanca (a fila retentaria).
        $this->expectException(WhatsappSendException::class);
        (new SendServerAlert($this->account->id))->handle(app(ProviderRegistry::class));
    }

    public function test_failed_registra_systemevent_e_faz_fallback_email(): void
    {
        Mail::fake();
        config()->set('servers.fallback_email', 'ops@empresa.com');
        $this->contato(['email' => 'fabio@empresa.com']);

        (new SendServerAlert($this->account->id))->failed(new WhatsappSendException('Evolution fora'));

        // Falha observavel nos Logs.
        $ev = SystemEvent::withoutAccountScope()->where('type', 'servidores')->where('level', 'error')->first();
        $this->assertNotNull($ev);
        $this->assertStringContainsString('Falha ao notificar', $ev->title);

        // Fallback e-mail para o contato + o global.
        Mail::assertSent(ServersAlertFallback::class, function ($mail) {
            return $mail->hasTo('fabio@empresa.com') && $mail->hasTo('ops@empresa.com');
        });
    }

    // ---- flag OFF: nada e enviado (S2 intacto) ---------------------------------

    public function test_flag_off_nada_e_enviado_e_silencioso_permanece(): void
    {
        config()->set('servers.notifications_enabled', false);
        Http::fake();
        $this->contato();

        $this->abrirCpuCritical();
        $this->evaluateCommand();

        Http::assertNothingSent();
        // Modo silencioso da S2 segue: SystemEvent "Teria notificado".
        $this->assertNotNull(SystemEvent::withoutAccountScope()->where('title', 'like', '%Teria notificado%')->first());
    }

    public function test_isolamento_nao_envia_para_contato_de_outra_empresa(): void
    {
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        // Contato da empresa B nao pode receber alerta da empresa A.
        $b = Account::create(['name' => 'Empresa B']);
        AlertContact::withoutAccountScope()->create(['account_id' => $b->id, 'name' => 'Intruso', 'phone' => '5511000000000', 'min_level' => 'warning', 'enabled' => true]);
        $this->contato();

        $this->abrirCpuCritical();
        $this->evaluateCommand();

        Http::assertNotSent(fn ($req) => $req['number'] === '5511000000000');
        Http::assertSent(fn ($req) => $req['number'] === '5511999990000');
    }

    private function donoId(): int
    {
        $u = User::create(['name' => 'Dono', 'email' => 'd@x.local', 'password' => bcrypt('x')]);
        $u->accounts()->attach($this->account->id, ['role' => 'owner']);

        return $u->id;
    }
}
