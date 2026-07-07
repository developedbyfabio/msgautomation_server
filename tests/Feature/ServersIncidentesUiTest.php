<?php

namespace Tests\Feature;

use App\Livewire\Servidores\Incidentes;
use App\Models\Account;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\User;
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
 * Servidores S2 — tela de Incidentes (owner-only): filtro, ACK (silencia mas
 * segue aberto e monitorado — resolve pela normalizacao), gates de acesso e
 * o grupo do menu escondido do operador.
 */
class ServersIncidentesUiTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private User $operador;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'Interna']);
        Channel::create(['account_id' => $this->account->id, 'instance' => 'inst-a', 'provider' => 'evolution', 'webhook_token' => 'tok-a', 'status' => 'connected']);
        AutoReplySetting::create(['account_id' => $this->account->id]);
        $this->server = Server::create(['account_id' => $this->account->id, 'name' => 'srv-ui', 'os' => 'linux', 'last_seen_at' => now()]);

        $this->owner = User::create(['name' => 'Dono', 'email' => 'dono@x.local', 'password' => Hash::make('senha-forte-123')]);
        $this->owner->accounts()->attach($this->account->id, ['role' => 'owner']);
        $this->operador = User::create(['name' => 'Sec', 'email' => 'sec@x.local', 'password' => Hash::make('senha-forte-123')]);
        $this->operador->accounts()->attach($this->account->id, ['role' => 'operador']);
    }

    private function incidente(array $extra = []): Incident
    {
        return Incident::withoutAccountScope()->create(array_merge([
            'account_id' => $this->account->id,
            'server_id' => $this->server->id,
            'metric' => 'cpu',
            'level' => 'critical',
            'status' => Incident::STATUS_FIRING,
            'open_key' => Incident::openKey($this->server->id, 'cpu'),
            'started_at' => now(),
        ], $extra));
    }

    public function test_owner_acessa_e_operador_403(): void
    {
        $this->actingAs($this->owner)->withSession(['tenancy.account_id' => $this->account->id])
            ->get(route('servidores.incidentes'))->assertOk()->assertSee('Incidentes');

        $this->actingAs($this->operador)->withSession(['tenancy.account_id' => $this->account->id])
            ->get(route('servidores.incidentes'))->assertForbidden();
    }

    public function test_grupo_do_menu_some_para_operador(): void
    {
        $this->actingAs($this->owner)->withSession(['tenancy.account_id' => $this->account->id])
            ->get(route('perfil'))
            ->assertSee(route('servidores.incidentes'))
            ->assertSee(route('servidores.alertas'));

        $this->actingAs($this->operador)->withSession(['tenancy.account_id' => $this->account->id])
            ->get(route('perfil'))
            ->assertDontSee(route('servidores.incidentes'))
            ->assertDontSee(route('servidores.alertas'));
    }

    public function test_ack_marca_reconhecido_com_autor(): void
    {
        $inc = $this->incidente();
        app(AccountContext::class)->set($this->account->id);
        $this->actingAs($this->owner);

        Livewire::test(Incidentes::class)->call('ack', $inc->id);

        $inc->refresh();
        $this->assertSame(Incident::STATUS_ACKNOWLEDGED, $inc->status);
        $this->assertSame($this->owner->id, $inc->acknowledged_by);
        $this->assertNotNull($inc->acknowledged_at);
        $this->assertNotNull($inc->open_key); // SEGUE aberto (ack nao fecha)
    }

    public function test_ack_forjado_de_operador_e_barrado(): void
    {
        $inc = $this->incidente();
        app(AccountContext::class)->set($this->account->id);
        $this->actingAs($this->operador);

        Livewire::test(Incidentes::class)->call('ack', $inc->id)->assertForbidden();

        $this->assertSame(Incident::STATUS_FIRING, $inc->fresh()->status);
    }

    public function test_reconhecido_nao_reabre_e_resolve_pela_normalizacao(): void
    {
        // Incidente real via avaliacao (cpu 97 por 150s), depois ack.
        $buffer = app(MetricsBuffer::class);
        foreach ([150, 120, 90, 60, 30, 0] as $age) {
            $buffer->push($this->server->id, ['received_at' => now()->getTimestamp() - $age, 'cpu_pct' => 97.0, 'mem_pct' => 10.0, 'disks' => [['mount' => '/', 'pct' => 20.0]]]);
        }
        AlertRuleDefaults::ensureFor($this->account->id);
        app(ServerEvaluator::class)->evaluate($this->server->fresh());
        $inc = Incident::withoutAccountScope()->sole();

        app(AccountContext::class)->set($this->account->id);
        $this->actingAs($this->owner);
        Livewire::test(Incidentes::class)->call('ack', $inc->id);

        // Condicao persiste: segue acknowledged (nao volta a firing, nao duplica).
        app(ServerEvaluator::class)->evaluate($this->server->fresh());
        $this->assertSame(Incident::STATUS_ACKNOWLEDGED, $inc->fresh()->status);
        $this->assertSame(1, Incident::withoutAccountScope()->count());

        // Normaliza: resolve MESMO estando acknowledged.
        foreach ([330, 270, 210, 150, 90, 30, 0] as $age) {
            $buffer->push($this->server->id, ['received_at' => now()->getTimestamp() - $age, 'cpu_pct' => 5.0, 'mem_pct' => 10.0, 'disks' => [['mount' => '/', 'pct' => 20.0]]]);
        }
        app(ServerEvaluator::class)->evaluate($this->server->fresh());
        $this->assertSame(Incident::STATUS_RESOLVED, $inc->fresh()->status);
    }

    public function test_filtro_por_estado(): void
    {
        $this->incidente(); // cpu aberto
        $this->incidente([
            'metric' => 'ram', 'status' => Incident::STATUS_RESOLVED,
            'open_key' => null, 'resolved_at' => now(),
        ]);

        app(AccountContext::class)->set($this->account->id);
        $this->actingAs($this->owner);

        Livewire::test(Incidentes::class)
            ->assertSee('CPU')->assertDontSee('RAM')          // default: abertos
            ->call('setFiltro', 'resolvidos')
            ->assertSee('RAM')->assertDontSee('CPU')
            ->call('setFiltro', 'todos')
            ->assertSee('CPU')->assertSee('RAM');
    }
}
