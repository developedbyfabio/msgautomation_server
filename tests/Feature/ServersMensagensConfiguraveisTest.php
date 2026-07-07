<?php

namespace Tests\Feature;

use App\Livewire\Servidores\Alertas;
use App\Models\Account;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\User;
use App\Servers\AlertContact;
use App\Servers\AlertMessage;
use App\Servers\AlertMessageResolver;
use App\Servers\AlertRule;
use App\Servers\AlertRuleDefaults;
use App\Servers\Incident;
use App\Servers\MetricsBuffer;
use App\Servers\Server;
use App\Servers\ServerEvaluator;
use App\Tenancy\AccountContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Servidores — mensagens configuraveis + cadencia de re-aviso por regra/nivel.
 * Cobre: "1 vez" nao re-avisa / "a cada N" re-avisa; variaveis substituidas;
 * rotacao A/B/C e repete C; resolucao 1 vez; UI grava cadencia+mensagens.
 */
class ServersMensagensConfiguraveisTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('servers.notifications_enabled', true);
        $this->account = Account::create(['name' => 'Empresa']);
        AutoReplySetting::create(['account_id' => $this->account->id]);
        Channel::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'instance' => 'inst-a', 'provider' => 'evolution',
            'credentials' => ['base_url' => 'https://evo.test', 'apikey' => 'k'], 'webhook_token' => 't', 'status' => 'connected',
        ]);
        $this->server = Server::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'name' => 'srv-a', 'os' => 'linux', 'last_seen_at' => now(),
        ]);
        AlertRuleDefaults::ensureFor($this->account->id);
        AlertContact::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'name' => 'Fabio', 'phone' => '5511999990000', 'min_level' => 'warning', 'enabled' => true,
        ]);
    }

    private function cpuRule(): AlertRule
    {
        return AlertRule::withoutAccountScope()->where('account_id', $this->account->id)
            ->whereNull('server_id')->where('metric', 'cpu')->first();
    }

    /** Abre CPU critical (buffer coberto + servidor reportando). */
    private function abrirCritical(): void
    {
        $this->server->forceFill(['last_seen_at' => now()])->save();
        $buffer = app(MetricsBuffer::class);
        foreach ([150, 120, 90, 60, 30, 0] as $age) {
            $buffer->push($this->server->id, ['received_at' => now()->getTimestamp() - $age, 'cpu_pct' => 97.0, 'mem_pct' => 10.0, 'disks' => [['mount' => '/', 'pct' => 20.0]]]);
        }
        app(ServerEvaluator::class)->evaluate($this->server->fresh());
    }

    private function tick(): void
    {
        $this->artisan('servers:evaluate')->assertSuccessful();
    }

    // ---- cadencia: 1 vez x a cada N --------------------------------------------

    public function test_avisar_uma_vez_nao_re_notifica(): void
    {
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        $this->cpuRule()->update(['critical_repeat_s' => null]); // avisar 1 vez

        $this->abrirCritical();
        $this->tick();
        Http::assertSentCount(1);

        $this->travel(2)->hours(); // muito tempo depois
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        $this->abrirCritical(); // condicao persiste
        $this->tick();
        Http::assertNothingSent(); // 1 vez: nao repete
    }

    public function test_re_avisar_a_cada_n_re_notifica_no_intervalo(): void
    {
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        $this->cpuRule()->update(['critical_repeat_s' => 3600]); // a cada 1h

        $this->abrirCritical();
        $this->tick();
        Http::assertSentCount(1);

        // Antes de 1h: nao repete.
        $this->travel(30)->minutes();
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        $this->abrirCritical();
        $this->tick();
        Http::assertNothingSent();

        // Passada 1h: re-avisa.
        $this->travel(40)->minutes();
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        $this->abrirCritical();
        $this->tick();
        Http::assertSentCount(1);
    }

    public function test_warning_pode_re_avisar_se_configurado(): void
    {
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        $this->cpuRule()->update(['warning_repeat_s' => 1800]); // warning a cada 30min

        // Abre warning (cpu 90 por 330s).
        $this->server->forceFill(['last_seen_at' => now()])->save();
        $buffer = app(MetricsBuffer::class);
        $warn = function () use ($buffer) {
            $this->server->forceFill(['last_seen_at' => now()])->save();
            foreach ([330, 300, 240, 180, 120, 60, 0] as $age) {
                $buffer->push($this->server->id, ['received_at' => now()->getTimestamp() - $age, 'cpu_pct' => 90.0, 'mem_pct' => 10.0, 'disks' => [['mount' => '/', 'pct' => 20.0]]]);
            }
        };
        $warn();
        app(ServerEvaluator::class)->evaluate($this->server->fresh());
        $this->tick();
        Http::assertSentCount(1);

        $this->travel(31)->minutes();
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        $warn();
        app(ServerEvaluator::class)->evaluate($this->server->fresh());
        $this->tick();
        Http::assertSentCount(1); // warning re-avisou (o novo poder do dono)
    }

    // ---- variaveis + rotacao ---------------------------------------------------

    public function test_variaveis_substituidas_no_texto(): void
    {
        $rule = $this->cpuRule();
        AlertMessage::withoutAccountScope()->create(['account_id' => $this->account->id, 'rule_id' => $rule->id, 'level' => 'critical', 'position' => 0, 'text' => '{servidor} esta com {metrica} em {valor} ({nivel})']);

        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        $this->abrirCritical();
        $this->tick();

        // Valores em pt-BR: {metrica} cpu -> "CPU", {nivel} critical -> "crítico".
        Http::assertSent(fn ($req) => $req['text'] === 'srv-a esta com CPU em 97% (crítico)');
    }

    public function test_rotacao_avanca_e_repete_a_ultima(): void
    {
        $rule = $this->cpuRule();
        $rule->update(['critical_repeat_s' => 3600]);
        foreach (['🔴 A {servidor}', '⚠️ B {servidor}', '🚨 C {servidor}'] as $p => $t) {
            AlertMessage::withoutAccountScope()->create(['account_id' => $this->account->id, 'rule_id' => $rule->id, 'level' => 'critical', 'position' => $p, 'text' => $t]);
        }

        // 1o disparo: A
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        $this->abrirCritical();
        $this->tick();
        Http::assertSent(fn ($req) => $req['text'] === '🔴 A srv-a');

        // 1o re-aviso: B
        $this->travel(61)->minutes();
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        $this->abrirCritical();
        $this->tick();
        Http::assertSent(fn ($req) => $req['text'] === '⚠️ B srv-a');

        // 2o re-aviso: C
        $this->travel(61)->minutes();
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        $this->abrirCritical();
        $this->tick();
        Http::assertSent(fn ($req) => $req['text'] === '🚨 C srv-a');

        // 3o re-aviso: repete C
        $this->travel(61)->minutes();
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        $this->abrirCritical();
        $this->tick();
        Http::assertSent(fn ($req) => $req['text'] === '🚨 C srv-a');
    }

    // ---- resolucao 1 vez com texto proprio -------------------------------------

    public function test_resolucao_usa_texto_proprio_uma_vez(): void
    {
        $rule = $this->cpuRule();
        AlertMessage::withoutAccountScope()->create(['account_id' => $this->account->id, 'rule_id' => $rule->id, 'level' => 'resolved', 'position' => 0, 'text' => '👍 {servidor}: {metrica} voltou ao normal']);

        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        $this->abrirCritical();
        $this->tick();

        // Normaliza -> resolve.
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        app(MetricsBuffer::class)->forget($this->server->id);
        $buffer = app(MetricsBuffer::class);
        foreach ([330, 270, 210, 150, 90, 30, 0] as $age) {
            $buffer->push($this->server->id, ['received_at' => now()->getTimestamp() - $age, 'cpu_pct' => 5.0, 'mem_pct' => 10.0, 'disks' => [['mount' => '/', 'pct' => 20.0]]]);
        }
        app(ServerEvaluator::class)->evaluate($this->server->fresh());
        $this->tick();
        Http::assertSent(fn ($req) => $req['text'] === '👍 srv-a: CPU voltou ao normal');

        // Nao re-manda resolucao.
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        $this->tick();
        Http::assertNothingSent();
    }

    // ---- resolver unitario -----------------------------------------------------

    public function test_resolver_default_quando_sem_mensagem(): void
    {
        $inc = Incident::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'server_id' => $this->server->id, 'rule_id' => $this->cpuRule()->id,
            'metric' => 'cpu', 'level' => 'critical', 'status' => 'firing',
            'open_key' => Incident::openKey($this->server->id, 'cpu'), 'value_at_fire' => 92, 'started_at' => now(),
        ]);
        $texto = app(AlertMessageResolver::class)->firing($inc);
        $this->assertStringContainsString('srv-a', $texto);
        $this->assertStringContainsString('92%', $texto);
        $this->assertStringContainsString('crítico', $texto); // {nivel} em pt-BR
    }

    // ---- UI grava cadencia + mensagens -----------------------------------------

    public function test_ui_salva_cadencia_e_mensagens(): void
    {
        $owner = User::create(['name' => 'D', 'email' => 'd@x.local', 'password' => Hash::make('x')]);
        $owner->accounts()->attach($this->account->id, ['role' => 'owner']);
        app(AccountContext::class)->set($this->account->id);
        $this->actingAs($owner);
        $rule = $this->cpuRule();

        Livewire::test(Alertas::class)
            ->call('edit', $rule->id)
            ->set('critical_repeat_on', true)
            ->set('critical_repeat_min', '15')
            ->set('msgsCritical', ['🔴 {servidor} A', '🚨 {servidor} B'])
            ->set('msgResolved', '✅ {servidor} ok')
            ->call('save')
            ->assertHasNoErrors();

        $rule->refresh();
        $this->assertSame(900, $rule->critical_repeat_s); // 15 min -> 900s
        $this->assertSame(2, AlertMessage::withoutAccountScope()->where('rule_id', $rule->id)->where('level', 'critical')->count());
        $this->assertSame('✅ {servidor} ok', AlertMessage::withoutAccountScope()->where('rule_id', $rule->id)->where('level', 'resolved')->first()->text);
    }

    public function test_ui_toggle_off_grava_null_avisar_uma_vez(): void
    {
        $owner = User::create(['name' => 'D', 'email' => 'd@x.local', 'password' => Hash::make('x')]);
        $owner->accounts()->attach($this->account->id, ['role' => 'owner']);
        app(AccountContext::class)->set($this->account->id);
        $this->actingAs($owner);
        $rule = $this->cpuRule();
        $rule->update(['critical_repeat_s' => 1800]);

        Livewire::test(Alertas::class)
            ->call('edit', $rule->id)
            ->assertSet('critical_repeat_on', true) // carregou ligado
            ->set('critical_repeat_on', false)      // dono escolhe "1 vez"
            ->call('save');

        $this->assertNull($rule->fresh()->critical_repeat_s);
    }
}
