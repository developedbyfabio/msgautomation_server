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
use App\Servers\AlertRule;
use App\Servers\AlertRuleDefaults;
use App\Servers\Incident;
use App\Servers\MetricsBuffer;
use App\Servers\Server;
use App\Servers\ServerEvaluator;
use App\Tenancy\AccountContext;
use App\Whatsapp\SystemConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Servidores — janela de horario por destinatario (F1), servidores por
 * destinatario (F2) e limiar por servidor (F3, motor da S2 exposto na UI).
 * Roteamento combinado: um contato recebe SO SE (escopo cobre o servidor) E
 * (nivel >= min_level) E (dentro da janela/fim de semana). Fora da janela, o
 * WhatsApp e suprimido POR CONTATO, mas incidente + conversa seguem registrados.
 */
class ServersJanelaEscopoLimiarTest extends TestCase
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
            'account_id' => $this->account->id, 'name' => 'srv-a', 'os' => 'linux', 'grupo' => 'producao', 'last_seen_at' => now(),
        ]);
        AlertRuleDefaults::ensureFor($this->account->id);
    }

    private function contato(array $extra = []): AlertContact
    {
        return AlertContact::withoutAccountScope()->create(array_merge([
            'account_id' => $this->account->id, 'name' => 'Fabio', 'phone' => '5511999990000',
            'min_level' => 'warning', 'enabled' => true, 'window_mode' => '24h', 'weekends' => true,
        ], $extra));
    }

    private function servidor(string $nome, string $grupo = 'producao'): Server
    {
        return Server::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'name' => $nome, 'os' => 'linux', 'grupo' => $grupo, 'last_seen_at' => now(),
        ]);
    }

    /** Cria um incidente ABERTO pendente de notificacao (notified_level NULL). */
    private function incidenteAberto(Server $s, string $level = 'critical', string $metric = 'cpu'): Incident
    {
        return Incident::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'server_id' => $s->id, 'metric' => $metric, 'level' => $level,
            'status' => 'firing', 'open_key' => Incident::openKey($s->id, $metric), 'value_at_fire' => 97, 'started_at' => now(),
        ]);
    }

    private function rodarJob(): void
    {
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        (new SendServerAlert($this->account->id))->handle(app(ProviderRegistry::class));
    }

    /** Usa recorded() (nao assertSent) para NAO estourar quando nada foi enviado. */
    private function enviouPara(string $phone): bool
    {
        return Http::recorded(fn ($req) => str_contains($req->url(), 'sendText') && ($req['number'] ?? null) === $phone)
            ->isNotEmpty();
    }

    // ============ FEATURE 1 — janela de horario ============

    public function test_janela_08_18_recebe_as_10h(): void
    {
        $this->travelTo(Carbon::parse('2026-07-08 13:00:00', 'UTC')); // 10:00 BRT (quarta)
        $this->contato(['window_mode' => 'custom', 'window_start' => '08:00', 'window_end' => '18:00']);
        $this->incidenteAberto($this->server);

        $this->rodarJob();
        $this->assertTrue($this->enviouPara('5511999990000'));
    }

    public function test_fora_da_janela_as_3h_nao_envia_mas_incidente_e_conversa_ficam(): void
    {
        $this->travelTo(Carbon::parse('2026-07-08 06:00:00', 'UTC')); // 03:00 BRT (fora de 08-18)
        $this->contato(['window_mode' => 'custom', 'window_start' => '08:00', 'window_end' => '18:00']);

        // Fluxo real: buffer -> evaluate cria o incidente E grava a conversa
        // (AlertNotifier), depois despacha o job (que suprime o WhatsApp as 3h).
        $buffer = app(MetricsBuffer::class);
        foreach ([150, 120, 90, 60, 30, 0] as $age) {
            $buffer->push($this->server->id, ['received_at' => now()->getTimestamp() - $age, 'cpu_pct' => 97.0, 'mem_pct' => 10.0, 'disks' => [['mount' => '/', 'pct' => 20.0]]]);
        }
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm']], 200)]);
        app(ServerEvaluator::class)->evaluate($this->server->fresh());
        $this->artisan('servers:evaluate')->assertSuccessful();

        // WhatsApp suprimido (fora da janela)...
        Http::assertNothingSent();
        // ...mas o FATO ficou: incidente aberto na aba Incidentes...
        $this->assertSame(1, Incident::withoutAccountScope()->where('server_id', $this->server->id)->whereNull('resolved_at')->count());
        // ...e a mensagem na conversa "Alertas de Infraestrutura".
        $this->assertGreaterThanOrEqual(1, IncomingMessage::withoutAccountScope()->where('remote_jid', SystemConversation::JID)->count());
    }

    public function test_fim_de_semana_desligado_nao_recebe_no_sabado(): void
    {
        $this->travelTo(Carbon::parse('2026-07-11 18:00:00', 'UTC')); // sabado 15:00 BRT
        $semFds = $this->contato(['name' => 'SemFDS', 'phone' => '5511111111111', 'weekends' => false]);
        $comFds = $this->contato(['name' => 'ComFDS', 'phone' => '5522222222222', 'weekends' => true]);
        $this->incidenteAberto($this->server);

        $this->rodarJob();
        $this->assertFalse($this->enviouPara('5511111111111')); // sem fim de semana -> nao recebe
        $this->assertTrue($this->enviouPara('5522222222222'));  // recebe fim de semana
    }

    public function test_fuso_20h_utc_e_17h_brt_dentro_da_janela(): void
    {
        $this->travelTo(Carbon::parse('2026-07-08 20:00:00', 'UTC')); // 17:00 BRT -> dentro de 08-18
        $this->contato(['window_mode' => 'custom', 'window_start' => '08:00', 'window_end' => '18:00']);
        $this->incidenteAberto($this->server);

        $this->rodarJob();
        $this->assertTrue($this->enviouPara('5511999990000'));
    }

    public function test_dois_contatos_um_na_janela_outro_fora(): void
    {
        $this->travelTo(Carbon::parse('2026-07-08 06:00:00', 'UTC')); // 03:00 BRT
        $this->contato(['name' => '24h', 'phone' => '5511111111111', 'window_mode' => '24h']);
        $this->contato(['name' => 'Comercial', 'phone' => '5522222222222', 'window_mode' => 'custom', 'window_start' => '08:00', 'window_end' => '18:00']);
        $this->incidenteAberto($this->server);

        $this->rodarJob();
        $this->assertTrue($this->enviouPara('5511111111111'));  // 24h recebe as 3h
        $this->assertFalse($this->enviouPara('5522222222222')); // janela comercial nao
    }

    // ============ FEATURE 2 — servidores por destinatario ============

    public function test_escopo_so_servidor_x_recebe_de_x_nao_de_y(): void
    {
        $y = $this->servidor('srv-y');
        $this->contato(['server_ids' => [$this->server->id]]); // so o X (srv-a)
        $this->incidenteAberto($this->server); // X
        $this->incidenteAberto($y);            // Y

        $this->rodarJob();
        // Recebe (o texto agrupa incidentes do escopo dele = so o X).
        $this->assertTrue($this->enviouPara('5511999990000'));
        // E o Y NAO entra no texto enviado.
        Http::assertSent(fn ($req) => ! str_contains((string) ($req['text'] ?? ''), 'srv-y'));
    }

    public function test_escopo_todos_recebe_de_qualquer_servidor(): void
    {
        $y = $this->servidor('srv-y');
        $this->contato(); // server_ids NULL = todos
        $this->incidenteAberto($y);

        $this->rodarJob();
        $this->assertTrue($this->enviouPara('5511999990000'));
    }

    /** Contato: so servidor X, so critical, janela 08-18. Recebe SO se as 3 condicoes casam. */
    private function contatoCombinado(): void
    {
        $this->contato(['server_ids' => [$this->server->id], 'min_level' => 'critical',
            'window_mode' => 'custom', 'window_start' => '08:00', 'window_end' => '18:00']);
    }

    public function test_combinado_critical_no_escopo_na_janela_recebe(): void
    {
        $this->travelTo(Carbon::parse('2026-07-08 13:00:00', 'UTC')); // 10:00 BRT
        $this->contatoCombinado();
        $this->incidenteAberto($this->server, 'critical');
        $this->rodarJob();
        $this->assertTrue($this->enviouPara('5511999990000'));
    }

    public function test_combinado_warning_abaixo_do_min_level_nao_recebe(): void
    {
        $this->travelTo(Carbon::parse('2026-07-08 13:00:00', 'UTC'));
        $this->contatoCombinado();
        $this->incidenteAberto($this->server, 'warning');
        $this->rodarJob();
        $this->assertFalse($this->enviouPara('5511999990000'));
    }

    public function test_combinado_fora_do_escopo_nao_recebe(): void
    {
        $this->travelTo(Carbon::parse('2026-07-08 13:00:00', 'UTC'));
        $y = $this->servidor('srv-y');
        $this->contatoCombinado();
        $this->incidenteAberto($y, 'critical'); // Y nao esta no escopo
        $this->rodarJob();
        $this->assertFalse($this->enviouPara('5511999990000'));
    }

    public function test_combinado_fora_da_janela_nao_recebe(): void
    {
        $this->travelTo(Carbon::parse('2026-07-08 06:00:00', 'UTC')); // 03:00 BRT
        $this->contatoCombinado();
        $this->incidenteAberto($this->server, 'critical');
        $this->rodarJob();
        $this->assertFalse($this->enviouPara('5511999990000'));
    }

    // ============ FEATURE 3 — limiar por servidor ============

    public function test_servidor_sem_sobrescrita_usa_o_global(): void
    {
        $regras = app(ServerEvaluator::class)->rulesFor($this->server->fresh());
        $this->assertTrue($regras['disk']->isGlobal()); // herda a regra global
    }

    public function test_disco_sobrescrito_95_nao_alerta_a_92_enquanto_global_alertaria(): void
    {
        // Global de disco deterministico: warning 85 / critical 90.
        AlertRule::withoutAccountScope()->whereNull('server_id')->where('metric', 'disk')
            ->update(['warning_threshold' => 85, 'critical_threshold' => 90, 'warning_for_s' => 0, 'critical_for_s' => 0, 'resolve_for_s' => 0]);

        // Servidor "vive no limite": sobrescrita SO dele em 95/98.
        $limite = $this->servidor('srv-limite');
        AlertRule::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'server_id' => $limite->id, 'metric' => 'disk', 'mount' => null,
            'warning_threshold' => 95, 'critical_threshold' => 98, 'warning_for_s' => 0, 'critical_for_s' => 0, 'resolve_for_s' => 0, 'enabled' => true,
        ]);

        // Ambos a 92% de disco.
        $buffer = app(MetricsBuffer::class);
        foreach ([$this->server, $limite] as $s) {
            $s->forceFill(['last_seen_at' => now()])->save();
            foreach ([120, 90, 60, 30, 0] as $age) {
                $buffer->push($s->id, ['received_at' => now()->getTimestamp() - $age, 'cpu_pct' => 5.0, 'mem_pct' => 5.0, 'disks' => [['mount' => '/', 'pct' => 92.0]]]);
            }
            app(ServerEvaluator::class)->evaluate($s->fresh());
        }

        // O servidor no padrao global (92 > 90) ABRE incidente de disco...
        $this->assertSame(1, Incident::withoutAccountScope()->where('server_id', $this->server->id)->where('metric', 'disk')->count());
        // ...o do limite sobrescrito (92 < 95) NAO.
        $this->assertSame(0, Incident::withoutAccountScope()->where('server_id', $limite->id)->where('metric', 'disk')->count());
    }

    public function test_voltar_ao_padrao_remove_a_sobrescrita(): void
    {
        $owner = User::create(['name' => 'D', 'email' => 'd@x.local', 'password' => Hash::make('x')]);
        $owner->accounts()->attach($this->account->id, ['role' => 'owner']);
        app(AccountContext::class)->set($this->account->id);
        $this->actingAs($owner);

        $sobrescrita = AlertRule::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'server_id' => $this->server->id, 'metric' => 'cpu', 'mount' => null,
            'warning_threshold' => 95, 'critical_threshold' => 99, 'warning_for_s' => 0, 'critical_for_s' => 0, 'enabled' => true,
        ]);

        Livewire::test(Alertas::class)
            ->set('servidorId', $this->server->id)
            ->call('askRemoveOverride', $sobrescrita->id)
            ->call('removeOverrideConfirmed');

        $this->assertNull(AlertRule::withoutAccountScope()->find($sobrescrita->id)); // removida
        // Volta a herdar o global (rulesFor devolve a global).
        $this->assertTrue(app(ServerEvaluator::class)->rulesFor($this->server->fresh())['cpu']->isGlobal());
    }
}
