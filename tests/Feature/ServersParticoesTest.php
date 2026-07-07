<?php

namespace Tests\Feature;

use App\Livewire\Servidores\Alertas;
use App\Models\Account;
use App\Models\User;
use App\Servers\AlertRule;
use App\Servers\AlertRuleDefaults;
use App\Servers\Incident;
use App\Servers\MetricsBuffer;
use App\Servers\Server;
use App\Servers\ServerEvaluator;
use App\Tenancy\AccountContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Servidores S4 — selecao de PARTICAO por servidor. A regra de disco resolve
 * por mount (particao > servidor > global); sobrescrita de particao desligada
 * silencia SO aquela particao; o incidente identifica o mount.
 */
class ServersParticoesTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'A']);
        $this->server = Server::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'name' => 'srv', 'os' => 'linux', 'last_seen_at' => now(),
        ]);
        AlertRuleDefaults::ensureFor($this->account->id);
    }

    /** Empurra 3 amostras (cobrindo o for_s de disco) com as particoes dadas. */
    private function pushDisks(array $disks): void
    {
        $buffer = app(MetricsBuffer::class);
        foreach ([90, 45, 0] as $age) {
            $buffer->push($this->server->id, [
                'received_at' => now()->getTimestamp() - $age,
                'cpu_pct' => 5.0, 'mem_pct' => 10.0, 'disks' => $disks,
            ]);
        }
    }

    private function evaluate(): void
    {
        app(ServerEvaluator::class)->evaluate($this->server->fresh());
    }

    public function test_disco_default_global_vale_para_todas_as_particoes(): void
    {
        // Sem sobrescrita: a global (85/95) vale para / (88%) e /srv (5%).
        $this->pushDisks([['mount' => '/', 'pct' => 88.0], ['mount' => '/srv', 'pct' => 5.0]]);
        $this->evaluate();

        $incidentes = Incident::withoutAccountScope()->get();
        $this->assertCount(1, $incidentes); // so o / (88 >= 85 warning)
        $this->assertSame('disk', $incidentes->first()->metric);
        $this->assertSame('/', $incidentes->first()->mount);
        $this->assertSame('warning', $incidentes->first()->level);
    }

    public function test_particao_desligada_silencia_so_ela(): void
    {
        // Sobrescrita da particao /srv desligada; / segue na global.
        AlertRule::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'server_id' => $this->server->id,
            'metric' => 'disk', 'mount' => '/srv', 'warning_threshold' => 85,
            'critical_threshold' => 95, 'warning_for_s' => 60, 'critical_for_s' => 60, 'enabled' => false,
        ]);

        // Ambas altas: / dispara; /srv esta silenciada.
        $this->pushDisks([['mount' => '/', 'pct' => 90.0], ['mount' => '/srv', 'pct' => 99.0]]);
        $this->evaluate();

        $incidentes = Incident::withoutAccountScope()->get();
        $this->assertCount(1, $incidentes);
        $this->assertSame('/', $incidentes->first()->mount); // /srv nao alertou
    }

    public function test_limiar_por_particao_sobrescreve_o_global(): void
    {
        // /boot com limiar critical proprio (50), bem abaixo do global (95).
        AlertRule::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'server_id' => $this->server->id,
            'metric' => 'disk', 'mount' => '/boot', 'warning_threshold' => 40,
            'critical_threshold' => 50, 'warning_for_s' => 60, 'critical_for_s' => 60, 'enabled' => true,
        ]);

        // /boot a 60% -> critical (limiar 50); / a 70% -> abaixo do global 85, nada.
        $this->pushDisks([['mount' => '/', 'pct' => 70.0], ['mount' => '/boot', 'pct' => 60.0]]);
        $this->evaluate();

        $inc = Incident::withoutAccountScope()->sole();
        $this->assertSame('/boot', $inc->mount);
        $this->assertSame('critical', $inc->level);
    }

    public function test_particao_do_servidor_vence_a_global(): void
    {
        // Global disk critical=95; sobrescrita do / neste servidor critical=60.
        AlertRule::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'server_id' => $this->server->id,
            'metric' => 'disk', 'mount' => '/', 'warning_threshold' => 50,
            'critical_threshold' => 60, 'warning_for_s' => 60, 'critical_for_s' => 60, 'enabled' => true,
        ]);

        $this->pushDisks([['mount' => '/', 'pct' => 70.0]]); // 70 >= 60 critical da sobrescrita
        $this->evaluate();

        $inc = Incident::withoutAccountScope()->sole();
        $this->assertSame('/', $inc->mount);
        $this->assertSame('critical', $inc->level);
    }

    public function test_disk_rule_for_precedencia(): void
    {
        $evaluator = app(ServerEvaluator::class);

        // So global: escolhe a global.
        $r = $evaluator->diskRuleFor($this->server, '/');
        $this->assertNull($r->server_id);
        $this->assertNull($r->mount);

        // Sobrescrita do servidor (mount NULL): vence a global.
        $srv = AlertRule::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'server_id' => $this->server->id,
            'metric' => 'disk', 'mount' => null, 'warning_threshold' => 70,
            'critical_threshold' => 80, 'warning_for_s' => 60, 'critical_for_s' => 60, 'enabled' => true,
        ]);
        $this->assertSame($srv->id, $evaluator->diskRuleFor($this->server->fresh(), '/')->id);

        // Sobrescrita da particao: vence a do servidor.
        $part = AlertRule::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'server_id' => $this->server->id,
            'metric' => 'disk', 'mount' => '/', 'warning_threshold' => 50,
            'critical_threshold' => 60, 'warning_for_s' => 60, 'critical_for_s' => 60, 'enabled' => true,
        ]);
        $this->assertSame($part->id, $evaluator->diskRuleFor($this->server->fresh(), '/')->id);
        // Outra particao sem sobrescrita cai na do servidor.
        $this->assertSame($srv->id, $evaluator->diskRuleFor($this->server->fresh(), '/var')->id);
    }

    // ---- UI (descoberta + toggle por particao) ---------------------------------

    private function ownerNoContexto(): void
    {
        $owner = User::create(['name' => 'Dono', 'email' => 'd@x.local', 'password' => Hash::make('x')]);
        $owner->accounts()->attach($this->account->id, ['role' => 'owner']);
        app(AccountContext::class)->set($this->account->id);
        $this->actingAs($owner);
    }

    public function test_ui_lista_particoes_reportadas_pelo_servidor(): void
    {
        $this->server->forceFill(['last_sample' => ['disks' => [
            ['mount' => '/', 'pct' => 88.0], ['mount' => '/srv', 'pct' => 5.0],
        ]]])->save();
        $this->ownerNoContexto();

        Livewire::test(Alertas::class)
            ->set('servidorId', $this->server->id)
            ->assertSee('/srv')
            ->assertSee('Particoes reportadas');
    }

    public function test_ui_silenciar_particao_cria_sobrescrita_desligada_e_so_ela_para_de_alertar(): void
    {
        $this->server->forceFill(['last_sample' => ['disks' => [
            ['mount' => '/', 'pct' => 90.0], ['mount' => '/srv', 'pct' => 99.0],
        ]]])->save();
        $this->ownerNoContexto();

        Livewire::test(Alertas::class)
            ->set('servidorId', $this->server->id)
            ->call('togglePartition', '/srv');

        $regra = AlertRule::withoutAccountScope()->where('server_id', $this->server->id)
            ->where('metric', 'disk')->where('mount', '/srv')->first();
        $this->assertNotNull($regra);
        $this->assertFalse($regra->enabled); // silenciada

        // Prova no avaliador: ambas altas -> so / abre incidente.
        $this->pushDisks([['mount' => '/', 'pct' => 90.0], ['mount' => '/srv', 'pct' => 99.0]]);
        $this->evaluate();
        $inc = Incident::withoutAccountScope()->sole();
        $this->assertSame('/', $inc->mount);
    }

    public function test_ui_operador_nao_silencia_particao(): void
    {
        $this->server->forceFill(['last_sample' => ['disks' => [['mount' => '/', 'pct' => 90.0]]]])->save();
        $operador = User::create(['name' => 'Op', 'email' => 'op@x.local', 'password' => Hash::make('x')]);
        $operador->accounts()->attach($this->account->id, ['role' => 'operador']);
        app(AccountContext::class)->set($this->account->id);
        $this->actingAs($operador);

        Livewire::test(Alertas::class)
            ->set('servidorId', $this->server->id)
            ->call('togglePartition', '/')
            ->assertForbidden();

        $this->assertSame(0, AlertRule::withoutAccountScope()->whereNotNull('mount')->count());
    }
}
