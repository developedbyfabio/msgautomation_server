<?php

namespace Tests\Feature;

use App\Channels\ProviderRegistry;
use App\Jobs\SendServerAlert;
use App\Livewire\Servidores\Alertas;
use App\Models\Account;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\IncomingMessage;
use App\Models\User;
use App\Servers\AlertContact;
use App\Servers\AlertMessageResolver;
use App\Servers\AlertNotifier;
use App\Servers\AlertRule;
use App\Servers\AlertRuleDefaults;
use App\Servers\Incident;
use App\Servers\Server;
use App\Servers\ServerAlertSetting;
use App\Tenancy\AccountContext;
use App\Whatsapp\SystemConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Separador editavel dos avisos agrupados + valores de {metrica}/{nivel} em pt-BR.
 */
class ServersSeparadorEIdiomaTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

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
        AlertRuleDefaults::ensureFor($this->account->id);
        AlertContact::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'name' => 'Fabio', 'phone' => '5511999990000', 'min_level' => 'warning', 'enabled' => true,
        ]);
    }

    private function servidor(string $nome, ?int $staleSecs = null): Server
    {
        return Server::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'name' => $nome, 'os' => 'linux',
            'host' => '10.0.0.9', 'grupo' => 'YELLL',
            'last_seen_at' => $staleSecs ? now()->subSeconds($staleSecs) : now(),
        ]);
    }

    // ---- separador de avisos agrupados ----------------------------------------

    /** Faz o job agrupar 2 avisos (2 servidores watchdog mudos) e devolve o texto enviado. */
    private function textoAgrupado(): string
    {
        $this->servidor('srv-a', 400);
        $this->servidor('srv-b', 400);
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        $this->artisan('servers:evaluate')->assertSuccessful();

        $texto = null;
        Http::assertSent(function ($req) use (&$texto) {
            $texto = $req['text'];

            return true;
        });

        return (string) $texto;
    }

    public function test_separador_linha_em_branco_entre_avisos(): void
    {
        ServerAlertSetting::withoutAccountScope()->create(['account_id' => $this->account->id, 'group_separator' => "\n\n"]);

        $texto = $this->textoAgrupado();
        $this->assertStringContainsString('srv-a', $texto);
        $this->assertStringContainsString('srv-b', $texto);
        $this->assertStringContainsString("\n\n", $texto); // linha em branco entre os avisos
    }

    public function test_separador_tracos_aparece_entre_avisos(): void
    {
        ServerAlertSetting::withoutAccountScope()->create(['account_id' => $this->account->id, 'group_separator' => "\n----------\n"]);

        $texto = $this->textoAgrupado();
        $this->assertStringContainsString('----------', $texto);
        // o traco fica ENTRE os dois avisos.
        $this->assertMatchesRegularExpression('/srv-[ab].*----------.*srv-[ab]/s', $texto);
    }

    public function test_um_aviso_so_nao_tem_separador(): void
    {
        ServerAlertSetting::withoutAccountScope()->create(['account_id' => $this->account->id, 'group_separator' => "\n----------\n"]);
        $this->servidor('srv-unico', 400); // so 1 servidor mudo -> 1 aviso
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        $this->artisan('servers:evaluate')->assertSuccessful();

        Http::assertSent(fn ($req) => str_contains($req['text'], 'srv-unico') && ! str_contains($req['text'], '----------'));
    }

    public function test_default_e_quebra_de_linha_simples(): void
    {
        // Sem registro de setting -> default "\n" (nao "\n\n").
        $this->assertSame("\n", ServerAlertSetting::separatorFor($this->account->id));
        $texto = $this->textoAgrupado();
        $this->assertStringNotContainsString("\n\n", $texto);
        $this->assertStringContainsString("\n", $texto);
    }

    // ---- valores em portugues --------------------------------------------------

    public function test_metrica_e_nivel_em_portugues_no_whatsapp(): void
    {
        // Disco critical: {metrica}->disco, {nivel}->critico.
        $srv = $this->servidor('srv-disk');
        AlertRule::withoutAccountScope()->where('account_id', $this->account->id)->whereNull('server_id')->where('metric', 'disk')->update(['critical_repeat_s' => 60]);
        Incident::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'server_id' => $srv->id,
            'rule_id' => AlertRule::withoutAccountScope()->where('account_id', $this->account->id)->whereNull('server_id')->where('metric', 'disk')->value('id'),
            'metric' => 'disk', 'mount' => '/', 'level' => 'critical', 'status' => 'firing',
            'open_key' => Incident::openKey($srv->id, 'disk', '/'), 'value_at_fire' => 95,
            'started_at' => now(), 'notified_level' => 'critical', 'notify_count' => 1, 'last_notified_at' => now()->subSeconds(120),
        ]);

        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        (new SendServerAlert($this->account->id))->handle(app(ProviderRegistry::class));

        Http::assertSent(function ($req) {
            return str_contains($req['text'], 'disco')      // {metrica} pt-BR
                && str_contains($req['text'], 'crítico')    // {nivel} pt-BR
                && ! str_contains($req['text'], 'critical') // sem ingles
                && ! str_contains($req['text'], 'Disco (/) critical');
        });
    }

    public function test_traducao_tambem_na_conversa_do_atendimento(): void
    {
        config()->set('servers.notifications_enabled', false); // AlertNotifier grava a conversa em toda transicao
        $srv = $this->servidor('srv-mem');
        $inc = Incident::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'server_id' => $srv->id,
            'rule_id' => AlertRule::withoutAccountScope()->where('account_id', $this->account->id)->whereNull('server_id')->where('metric', 'ram')->value('id'),
            'metric' => 'ram', 'level' => 'warning', 'status' => 'firing',
            'open_key' => Incident::openKey($srv->id, 'ram'), 'value_at_fire' => 88, 'started_at' => now(),
        ]);

        app(AlertNotifier::class)->transition($inc, 'firing');

        $msg = IncomingMessage::withoutAccountScope()->where('remote_jid', SystemConversation::JID)->latest('id')->first();
        $this->assertNotNull($msg);
        $this->assertStringContainsString('memória', $msg->text); // ram -> memoria
        $this->assertStringContainsString('aviso', $msg->text);   // warning -> aviso
        $this->assertStringNotContainsString('warning', $msg->text);
    }

    public function test_load_vira_carga_e_watchdog_sem_reportar(): void
    {
        $srv = $this->servidor('srv-x');
        $incLoad = Incident::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'server_id' => $srv->id, 'metric' => 'load', 'level' => 'critical',
            'status' => 'firing', 'open_key' => Incident::openKey($srv->id, 'load'), 'value_at_fire' => 3.1, 'started_at' => now(),
        ]);
        $incWd = Incident::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'server_id' => $srv->id, 'metric' => 'watchdog', 'level' => 'warning',
            'status' => 'firing', 'open_key' => Incident::openKey($srv->id, 'watchdog'), 'value_at_fire' => 200, 'started_at' => now(),
        ]);
        $resolver = app(AlertMessageResolver::class);

        $this->assertStringContainsString('carga', $resolver->firing($incLoad));
        $this->assertStringContainsString('sem reportar', $resolver->firing($incWd));
    }

    // ---- UI salva o separador --------------------------------------------------

    public function test_ui_salva_separador_owner_only(): void
    {
        $owner = User::create(['name' => 'D', 'email' => 'd@x.local', 'password' => Hash::make('x')]);
        $owner->accounts()->attach($this->account->id, ['role' => 'owner']);
        $operador = User::create(['name' => 'O', 'email' => 'o@x.local', 'password' => Hash::make('x')]);
        $operador->accounts()->attach($this->account->id, ['role' => 'operador']);
        app(AccountContext::class)->set($this->account->id);

        // Operador forjando -> 403.
        $this->actingAs($operador);
        Livewire::test(Alertas::class)->set('groupSeparator', "\n\n")->call('salvarSeparador')->assertForbidden();

        // Owner: preset traços e salva.
        $this->actingAs($owner);
        Livewire::test(Alertas::class)
            ->call('setSeparadorPreset', 'tracos')
            ->assertSet('groupSeparator', "\n----------\n")
            ->call('salvarSeparador');

        $this->assertSame("\n----------\n", ServerAlertSetting::separatorFor($this->account->id));
    }
}
