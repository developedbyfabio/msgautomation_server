<?php

namespace Tests\Feature;

use App\Livewire\Servidores\Alertas;
use App\Models\Account;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\User;
use App\Servers\AlertContact;
use App\Servers\AlertRule;
use App\Servers\Server;
use App\Tenancy\AccountContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Servidores S2 — tela de Alertas (owner-only): defaults garantidos no mount,
 * edicao de regra global, sobrescrita por servidor (criar/remover; global
 * nunca e removivel), validacao warning <= critical e gates de acesso.
 */
class ServersAlertasUiTest extends TestCase
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
        $this->server = Server::create(['account_id' => $this->account->id, 'name' => 'srv-regras', 'os' => 'linux']);

        $this->owner = User::create(['name' => 'Dono', 'email' => 'dono@x.local', 'password' => Hash::make('senha-forte-123')]);
        $this->owner->accounts()->attach($this->account->id, ['role' => 'owner']);
        $this->operador = User::create(['name' => 'Sec', 'email' => 'sec@x.local', 'password' => Hash::make('senha-forte-123')]);
        $this->operador->accounts()->attach($this->account->id, ['role' => 'operador']);
    }

    private function comoOwner(): void
    {
        app(AccountContext::class)->set($this->account->id);
        $this->actingAs($this->owner);
    }

    public function test_owner_ve_os_padroes_e_operador_403(): void
    {
        $this->actingAs($this->owner)->withSession(['tenancy.account_id' => $this->account->id])
            ->get(route('servidores.alertas'))
            ->assertOk()
            ->assertSee('CPU')
            ->assertSee('watchdog'); // rotulo "Sem reportar (watchdog)"

        // mount() garantiu os 6 padroes globais.
        $this->assertSame(6, AlertRule::withoutAccountScope()->whereNull('server_id')->count());

        $this->actingAs($this->operador)->withSession(['tenancy.account_id' => $this->account->id])
            ->get(route('servidores.alertas'))->assertForbidden();
    }

    public function test_editar_regra_global_persiste(): void
    {
        $this->comoOwner();
        $comp = Livewire::test(Alertas::class);
        $cpu = AlertRule::withoutAccountScope()->whereNull('server_id')->where('metric', 'cpu')->first();

        $comp->call('edit', $cpu->id)
            ->set('warning_threshold', '80')
            ->set('critical_threshold', '90')
            ->set('critical_for_s', '60')
            ->call('save')
            ->assertHasNoErrors();

        $cpu->refresh();
        $this->assertSame(80.0, $cpu->warning_threshold);
        $this->assertSame(90.0, $cpu->critical_threshold);
        $this->assertSame(60, $cpu->critical_for_s);
    }

    public function test_warning_maior_que_critical_e_rejeitado(): void
    {
        $this->comoOwner();
        $cpu = Livewire::test(Alertas::class); // mount garante defaults
        $regra = AlertRule::withoutAccountScope()->whereNull('server_id')->where('metric', 'cpu')->first();

        $cpu->call('edit', $regra->id)
            ->set('warning_threshold', '99')
            ->set('critical_threshold', '90')
            ->call('save')
            ->assertHasErrors(['warning_threshold']);
    }

    public function test_sobrescrever_cria_copia_da_efetiva_e_remover_volta_ao_padrao(): void
    {
        $this->comoOwner();
        $comp = Livewire::test(Alertas::class)
            ->set('servidorId', $this->server->id)
            ->call('override', 'cpu');

        $override = AlertRule::withoutAccountScope()->where('server_id', $this->server->id)->where('metric', 'cpu')->first();
        $this->assertNotNull($override);
        $this->assertSame(95.0, $override->critical_threshold); // copiou a efetiva (default global)

        $comp->call('askRemoveOverride', $override->id)->call('removeOverrideConfirmed');

        $this->assertNull(AlertRule::withoutAccountScope()->where('server_id', $this->server->id)->where('metric', 'cpu')->first());
        $this->assertSame(6, AlertRule::withoutAccountScope()->whereNull('server_id')->count()); // globais intactas
    }

    public function test_regra_global_nunca_e_removida(): void
    {
        $this->comoOwner();
        $global = null;
        $comp = Livewire::test(Alertas::class);
        $global = AlertRule::withoutAccountScope()->whereNull('server_id')->where('metric', 'cpu')->first();

        $comp->call('askRemoveOverride', $global->id)->call('removeOverrideConfirmed');

        $this->assertNotNull($global->fresh()); // whereNotNull('server_id') protege
    }

    public function test_acao_forjada_de_operador_e_barrada(): void
    {
        app(AccountContext::class)->set($this->account->id);
        // O mount roda como owner primeiro para os defaults existirem.
        $this->actingAs($this->owner);
        Livewire::test(Alertas::class);
        $cpu = AlertRule::withoutAccountScope()->whereNull('server_id')->where('metric', 'cpu')->first();

        $this->actingAs($this->operador);
        Livewire::test(Alertas::class)
            ->call('edit', $cpu->id)
            ->set('critical_threshold', '10')
            ->call('save')
            ->assertForbidden();

        $this->assertSame(95.0, $cpu->fresh()->critical_threshold); // intacta
    }

    // ---- Destinatarios (roteamento) --------------------------------------------

    public function test_owner_cadastra_destinatario(): void
    {
        $this->comoOwner();

        Livewire::test(Alertas::class)
            ->call('novoContato')
            ->set('c_name', 'Fabio')
            ->set('c_phone', '(55) 11 99999-0000')
            ->set('c_email', 'fabio@x.local')
            ->set('c_min_level', 'critical')
            ->call('saveContato')
            ->assertHasNoErrors();

        $c = AlertContact::withoutAccountScope()->where('account_id', $this->account->id)->sole();
        $this->assertSame('5511999990000', $c->phone); // normalizado (so digitos)
        $this->assertSame('critical', $c->min_level);
        $this->assertSame('fabio@x.local', $c->email);
    }

    public function test_escopo_selecionado_grava_server_ids_e_limpa_alvo_legado(): void
    {
        $this->comoOwner();

        Livewire::test(Alertas::class)
            ->call('novoContato')
            ->set('c_name', 'OnCall')
            ->set('c_phone', '5511888880000')
            ->set('c_server_scope', 'selected')
            ->set('c_server_ids', [$this->server->id])
            ->call('saveContato')
            ->assertHasNoErrors();

        $c = AlertContact::withoutAccountScope()->where('name', 'OnCall')->sole();
        $this->assertSame([$this->server->id], array_map('intval', $c->server_ids)); // escopo por selecao
        $this->assertNull($c->server_id); // alvo legado limpo (a UI usa server_ids)
        $this->assertNull($c->grupo);
    }

    public function test_escopo_selecionado_sem_servidor_e_rejeitado(): void
    {
        $this->comoOwner();

        Livewire::test(Alertas::class)
            ->call('novoContato')
            ->set('c_name', 'Vazio')
            ->set('c_phone', '5511888880000')
            ->set('c_server_scope', 'selected')
            ->set('c_server_ids', [])
            ->call('saveContato')
            ->assertHasErrors('c_server_ids');
    }

    public function test_janela_custom_persiste_inicio_fim_e_fim_de_semana(): void
    {
        $this->comoOwner();

        Livewire::test(Alertas::class)
            ->call('novoContato')
            ->set('c_name', 'Comercial')
            ->set('c_phone', '5511777770000')
            ->set('c_window_mode', 'custom')
            ->set('c_window_start', '08:00')
            ->set('c_window_end', '18:00')
            ->set('c_weekends', false)
            ->call('saveContato')
            ->assertHasNoErrors();

        $c = AlertContact::withoutAccountScope()->where('name', 'Comercial')->sole();
        $this->assertSame('custom', $c->window_mode);
        $this->assertSame('08:00', $c->window_start);
        $this->assertSame('18:00', $c->window_end);
        $this->assertFalse((bool) $c->weekends);
    }

    public function test_operador_nao_cadastra_destinatario(): void
    {
        app(AccountContext::class)->set($this->account->id);
        $this->actingAs($this->operador);

        Livewire::test(Alertas::class)
            ->call('novoContato')
            ->set('c_name', 'X')
            ->set('c_phone', '5511000000000')
            ->call('saveContato')
            ->assertForbidden();

        $this->assertSame(0, AlertContact::withoutAccountScope()->count());
    }
}
