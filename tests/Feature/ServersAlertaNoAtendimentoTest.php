<?php

namespace Tests\Feature;

use App\Jobs\SendAutoReply;
use App\Kanban\BoardEngine;
use App\Livewire\Contatos;
use App\Metrics\PainelMetrics;
use App\Models\Account;
use App\Models\AutoReplyLog;
use App\Models\AutoReplySetting;
use App\Models\Card;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\IncomingMessage;
use App\Models\User;
use App\Servers\AlertContact;
use App\Servers\AlertNotifier;
use App\Servers\AlertRuleDefaults;
use App\Servers\Incident;
use App\Servers\MetricsBuffer;
use App\Servers\Server;
use App\Servers\ServerEvaluator;
use App\Tenancy\AccountContext;
use App\Whatsapp\Proactive\AudienceResolver;
use App\Whatsapp\SystemConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Feature 2 — alerta de servidor visível como mensagem no Atendimento, numa
 * conversa de SISTEMA ("Alertas de Infraestrutura") 100% ISOLADA do pipeline.
 * A gravação acontece em toda transição (mudo ou não); o envio pelo WhatsApp
 * continua idêntico (transporte direto, só quando o flag está ON).
 */
class ServersAlertaNoAtendimentoTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'Empresa']);
        AutoReplySetting::create(['account_id' => $this->account->id]);
        $this->server = Server::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'name' => 'srv-a', 'os' => 'linux', 'last_seen_at' => now(),
        ]);
        AlertRuleDefaults::ensureFor($this->account->id);
    }

    private function incidente(array $extra = []): Incident
    {
        return Incident::withoutAccountScope()->create(array_merge([
            'account_id' => $this->account->id, 'server_id' => $this->server->id,
            'metric' => 'cpu', 'level' => 'critical', 'status' => 'firing',
            'open_key' => Incident::openKey($this->server->id, 'cpu'),
            'value_at_fire' => 97, 'started_at' => now(),
        ], $extra));
    }

    // ---- mensagem aparece na conversa de sistema -------------------------------

    public function test_transicao_grava_mensagem_na_conversa_de_sistema(): void
    {
        $inc = $this->incidente();
        app(AlertNotifier::class)->transition($inc, 'firing');

        $msg = IncomingMessage::withoutAccountScope()->where('remote_jid', SystemConversation::JID)->first();
        $this->assertNotNull($msg);
        $this->assertSame($this->account->id, $msg->account_id);
        $this->assertFalse((bool) $msg->from_me);
        $this->assertStringContainsString('srv-a', $msg->text);   // servidor
        $this->assertStringContainsString('CPU', $msg->text);      // metrica (pt-BR)
        $this->assertStringContainsString('crítico', $msg->text);  // nivel (pt-BR)

        // Resolucao tambem grava.
        $inc->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save();
        app(AlertNotifier::class)->transition($inc, 'resolved');
        $this->assertSame(2, IncomingMessage::withoutAccountScope()->where('remote_jid', SystemConversation::JID)->count());
        $this->assertStringContainsString('normalizado',
            IncomingMessage::withoutAccountScope()->where('remote_jid', SystemConversation::JID)->latest('id')->first()->text);
    }

    public function test_contato_de_sistema_criado_uma_vez_idempotente(): void
    {
        $this->incidente();
        app(AlertNotifier::class)->transition($this->incidente(['metric' => 'ram', 'open_key' => Incident::openKey($this->server->id, 'ram')]), 'firing');
        app(AlertNotifier::class)->transition(Incident::withoutAccountScope()->first(), 'firing');

        $this->assertSame(1, Contact::withoutAccountScope()->where('remote_jid', SystemConversation::JID)->count());
        $c = Contact::withoutAccountScope()->where('remote_jid', SystemConversation::JID)->first();
        $this->assertTrue((bool) $c->is_system);
    }

    // ---- robô NÃO responde à conversa de sistema (o teste que protege o produto) --

    public function test_robo_nao_responde_a_mensagem_de_sistema(): void
    {
        Queue::fake();
        app(AlertNotifier::class)->transition($this->incidente(), 'firing');

        // Gravou a mensagem, mas NENHUMA auto-resposta foi gerada nem enfileirada
        // (insercao direta, sem evento de dominio -> pipeline nunca invocado).
        $this->assertSame(1, IncomingMessage::withoutAccountScope()->where('remote_jid', SystemConversation::JID)->count());
        $this->assertSame(0, AutoReplyLog::withoutAccountScope()->count());
        Queue::assertNotPushed(SendAutoReply::class);
    }

    // ---- isolada de campanha / clientes / kanban / métricas --------------------

    public function test_isolada_de_campanha_e_clientes(): void
    {
        app(SystemConversation::class)->ensureContact($this->account->id);
        // Contato real "salvo" para a tela Clientes.
        Contact::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'remote_jid' => '5511999@s.whatsapp.net',
            'push_name' => 'Cliente', 'saved' => true, 'proactive_opt_in' => true,
        ]);
        $sys = Contact::withoutAccountScope()->where('remote_jid', SystemConversation::JID)->first();

        // Campanha: sistema nunca entra no publico (nem por selecao explicita).
        app(AccountContext::class)->set($this->account->id);
        $resolver = app(AudienceResolver::class);
        $res = $resolver->resolve($this->account->id, 'contatos', ['contact_ids' => [$sys->id]]);
        $ids = collect($res['eligiveis'] ?? $res['elegiveis'] ?? [])->pluck('id');
        $this->assertNotContains($sys->id, $ids->all());

        // Clientes: sistema nao aparece na lista.
        $owner = User::create(['name' => 'D', 'email' => 'd@x.local', 'password' => Hash::make('x')]);
        $owner->accounts()->attach($this->account->id, ['role' => 'owner']);
        $this->actingAs($owner);
        Livewire::test(Contatos::class)
            ->assertSee('5511999')
            ->assertDontSee(SystemConversation::NAME);
    }

    public function test_isolada_do_kanban(): void
    {
        app(SystemConversation::class)->ensureContact($this->account->id);
        // BoardEngine ignora o JID de sistema (como faz com grupos).
        app(BoardEngine::class)->apply('mensagem_recebida', $this->account->id, SystemConversation::JID, 1);

        $this->assertSame(0, Card::withoutAccountScope()
            ->whereHas('contact', fn ($q) => $q->where('remote_jid', SystemConversation::JID))->count());
    }

    public function test_nao_conta_nas_metricas_de_recebidas(): void
    {
        app(AlertNotifier::class)->transition($this->incidente(), 'firing'); // grava na conversa de sistema
        $dados = app(PainelMetrics::class)->dados($this->account->id, 'hoje');
        $this->assertSame(0, $dados['resumo']['recebidas']); // alerta de sistema nao conta
    }

    // ---- envio intacto (flag ON) + grava; flag OFF grava sem enviar ------------

    public function test_flag_on_envia_pelo_transporte_e_grava_na_conversa(): void
    {
        config()->set('servers.notifications_enabled', true);
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'm1']], 200)]);
        Channel::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'instance' => 'inst-a', 'provider' => 'evolution',
            'credentials' => ['base_url' => 'https://evo.test', 'apikey' => 'k'], 'webhook_token' => 't', 'status' => 'connected',
        ]);
        AlertContact::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'name' => 'Fabio', 'phone' => '5511999990000', 'min_level' => 'warning', 'enabled' => true,
        ]);

        // Ciclo real: buffer critico -> avalia -> transicao (grava conversa) -> command despacha job -> envia.
        $buffer = app(MetricsBuffer::class);
        foreach ([150, 120, 90, 60, 30, 0] as $age) {
            $buffer->push($this->server->id, ['received_at' => now()->getTimestamp() - $age, 'cpu_pct' => 97.0, 'mem_pct' => 10.0, 'disks' => [['mount' => '/', 'pct' => 20.0]]]);
        }
        app(ServerEvaluator::class)->evaluate($this->server->fresh());
        $this->artisan('servers:evaluate')->assertSuccessful();

        // Envio intacto: saiu pelo transporte direto (a feature nao mudou o caminho).
        Http::assertSent(fn ($req) => str_contains($req->url(), 'sendText') && $req['number'] === '5511999990000');
        // E a mensagem aparece na conversa de sistema.
        $this->assertGreaterThanOrEqual(1, IncomingMessage::withoutAccountScope()->where('remote_jid', SystemConversation::JID)->count());
    }

    public function test_flag_off_grava_conversa_mas_nao_envia(): void
    {
        $this->assertFalse((bool) config('servers.notifications_enabled')); // OFF por padrao (phpunit)
        Http::fake();

        app(AlertNotifier::class)->transition($this->incidente(), 'firing');

        Http::assertNothingSent(); // mudo: nada de WhatsApp
        // Mas o historico visivel foi gravado na conversa de sistema.
        $this->assertSame(1, IncomingMessage::withoutAccountScope()->where('remote_jid', SystemConversation::JID)->count());
    }
}
