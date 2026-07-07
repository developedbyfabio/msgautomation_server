<?php

namespace Tests\Feature;

use App\Channels\ProviderRegistry;
use App\Jobs\SendServerAlert;
use App\Livewire\Servidores\Alertas;
use App\Livewire\Servidores\Incidentes;
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
use App\Servers\IncidentManager;
use App\Servers\Server;
use App\Tenancy\AccountContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Correcao P2/P3: re-aviso de metrica (nao-reconhecido) reenvia; ack silencia e
 * "Reativar avisos" retoma; variaveis {ip}/{grupo} substituidas; mensagem padrao
 * editavel pre-preenchida na UI.
 */
class ServersReavisoEVariaveisTest extends TestCase
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
            'account_id' => $this->account->id, 'name' => 'DEV-YELLL', 'os' => 'linux',
            'host' => '10.40.132.19', 'grupo' => 'YELLL', 'last_seen_at' => now(),
        ]);
        AlertRuleDefaults::ensureFor($this->account->id);
        AlertContact::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'name' => 'Fabio', 'phone' => '5511999990000', 'min_level' => 'warning', 'enabled' => true,
        ]);
    }

    private function diskRule(): AlertRule
    {
        return AlertRule::withoutAccountScope()->where('account_id', $this->account->id)
            ->whereNull('server_id')->where('metric', 'disk')->first();
    }

    private function incidenteDisco(array $extra = []): Incident
    {
        return Incident::withoutAccountScope()->create(array_merge([
            'account_id' => $this->account->id, 'server_id' => $this->server->id, 'rule_id' => $this->diskRule()->id,
            'metric' => 'disk', 'mount' => '/', 'level' => 'critical', 'status' => 'firing',
            'open_key' => Incident::openKey($this->server->id, 'disk', '/'), 'value_at_fire' => 97,
            'started_at' => now()->subMinutes(10), 'notified_level' => 'critical', 'notify_count' => 1,
            'last_notified_at' => now()->subSeconds(120),
        ], $extra));
    }

    private function donoId(): int
    {
        $u = User::create(['name' => 'Dono', 'email' => 'dono'.uniqid().'@x.local', 'password' => Hash::make('x')]);
        $u->accounts()->attach($this->account->id, ['role' => 'owner']);

        return $u->id;
    }

    // ---- P2: re-aviso de disco NAO-reconhecido dispara -------------------------

    public function test_disco_nao_reconhecido_reavisa_no_intervalo(): void
    {
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        $this->diskRule()->update(['critical_repeat_s' => 60]); // 1 min
        $inc = $this->incidenteDisco(); // last_notified ha 120s, firing, nao-acked

        $this->assertTrue(SendServerAlert::hasPending($this->account->id));
        (new SendServerAlert($this->account->id))->handle(app(ProviderRegistry::class));

        Http::assertSentCount(1); // re-avisou
        $inc->refresh();
        $this->assertSame(2, $inc->notify_count); // avancou a rotacao
    }

    // ---- P2: ack silencia; reativar retoma -------------------------------------

    public function test_reconhecido_nao_reavisa_e_reativar_retoma(): void
    {
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        $this->diskRule()->update(['critical_repeat_s' => 60]);
        $inc = $this->incidenteDisco(['status' => 'acknowledged', 'acknowledged_at' => now(), 'acknowledged_by' => $this->donoId()]);

        // Reconhecido: nao reavisa.
        $this->assertFalse(SendServerAlert::hasPending($this->account->id));
        (new SendServerAlert($this->account->id))->handle(app(ProviderRegistry::class));
        Http::assertNothingSent();

        // Reativar (des-reconhecer) -> volta a firing -> reavisa.
        app(IncidentManager::class)->reactivate($inc);
        $inc->refresh();
        $this->assertSame('firing', $inc->status);
        $this->assertNull($inc->acknowledged_at);

        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        $this->assertTrue(SendServerAlert::hasPending($this->account->id));
        (new SendServerAlert($this->account->id))->handle(app(ProviderRegistry::class));
        Http::assertSentCount(1); // re-aviso retomado
    }

    public function test_reactivate_pela_tela_owner_only(): void
    {
        $inc = $this->incidenteDisco(['status' => 'acknowledged', 'acknowledged_at' => now(), 'acknowledged_by' => $this->donoId()]);
        $owner = User::create(['name' => 'D', 'email' => 'd@x.local', 'password' => Hash::make('x')]);
        $owner->accounts()->attach($this->account->id, ['role' => 'owner']);
        $operador = User::create(['name' => 'O', 'email' => 'o@x.local', 'password' => Hash::make('x')]);
        $operador->accounts()->attach($this->account->id, ['role' => 'operador']);
        app(AccountContext::class)->set($this->account->id);

        // Operador forjando -> 403.
        $this->actingAs($operador);
        Livewire::test(Incidentes::class)->call('reactivate', $inc->id)->assertForbidden();
        $this->assertSame('acknowledged', $inc->fresh()->status);

        // Owner -> reativa.
        $this->actingAs($owner);
        Livewire::test(Incidentes::class)->call('reactivate', $inc->id);
        $this->assertSame('firing', $inc->fresh()->status);
    }

    // ---- P3: variaveis {ip} e {grupo} ------------------------------------------

    public function test_variaveis_ip_e_grupo_substituidas(): void
    {
        $rule = $this->diskRule();
        AlertMessage::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'rule_id' => $rule->id, 'level' => 'critical', 'position' => 0,
            'text' => '🔴 {servidor} ({ip}, grupo {grupo}): {metrica} ({particao}) em {valor}',
        ]);
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        $this->diskRule()->update(['critical_repeat_s' => 60]);
        $this->incidenteDisco();

        (new SendServerAlert($this->account->id))->handle(app(ProviderRegistry::class));

        Http::assertSent(fn ($req) => $req['text'] === '🔴 DEV-YELLL (10.40.132.19, grupo YELLL): Disco (/) em 97%');
    }

    // ---- P3: mensagem padrao pre-preenchida e editavel na UI -------------------

    public function test_ui_pre_preenche_mensagem_padrao_editavel(): void
    {
        $owner = User::create(['name' => 'D', 'email' => 'd@x.local', 'password' => Hash::make('x')]);
        $owner->accounts()->attach($this->account->id, ['role' => 'owner']);
        app(AccountContext::class)->set($this->account->id);
        $this->actingAs($owner);
        $rule = $this->diskRule(); // sem mensagens proprias

        $comp = Livewire::test(Alertas::class)->call('edit', $rule->id);
        // A 1a mensagem vem preenchida com o template padrao (nao vazio).
        $this->assertNotEmpty($comp->get('msgsCritical'));
        $this->assertStringContainsString('{servidor}', $comp->get('msgsCritical')[0]);
        $this->assertStringContainsString('{ip}', $comp->get('msgsCritical')[0]);
        $this->assertStringContainsString('{particao}', $comp->get('msgsCritical')[0]); // disco -> com particao
        $this->assertNotEmpty($comp->get('msgResolved'));

        // Editar a padrao e salvar persiste como mensagem propria.
        $comp->set('msgsCritical', ['🚨 {servidor} ({ip}) DISCO {valor}'])->call('save')->assertHasNoErrors();
        $this->assertSame('🚨 {servidor} ({ip}) DISCO {valor}',
            AlertMessage::withoutAccountScope()->where('rule_id', $rule->id)->where('level', 'critical')->first()->text);
    }

    public function test_variaveis_documentadas_incluem_ip_e_grupo(): void
    {
        $this->assertArrayHasKey('{ip}', AlertMessageResolver::VARIAVEIS);
        $this->assertArrayHasKey('{grupo}', AlertMessageResolver::VARIAVEIS);
    }
}
