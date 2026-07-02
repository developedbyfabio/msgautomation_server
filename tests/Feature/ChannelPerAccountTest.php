<?php

namespace Tests\Feature;

use App\Channels\Evolution\ChannelProvisioner;
use App\Livewire\StatusConexao;
use App\Models\Account;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\User;
use App\Tenancy\AccountContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * MT-2 — canal/instancia POR CONTA. Provas (Evolution SEMPRE mockada):
 *  - provisionamento cria canal (instancia unica, token, credenciais cifradas)
 *    + instancia na Evolution + webhook por TOKEN; idempotente;
 *  - instancia viva com webhook DIVERGENTE fica intocada (gate do migrate);
 *  - telas de conexao operam no canal DA CONTA (credenciais do canal) e o sync
 *    de status nunca toca canal de outra conta;
 *  - gate de conexao por conta (A desconectada nao bloqueia B);
 *  - msg:channel:sync-env migra env -> canal, idempotente;
 *  - evolution:webhook:migrate: dry-run nao muda nada; --apply aponta pro token.
 */
class ChannelPerAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.evolution.base_url' => 'http://env-host:8090',
            'services.evolution.api_key' => 'chave-env',
            'services.evolution.webhook_url' => 'http://app-host:8190/webhook/evolution',
        ]);
        app(AccountContext::class)->clear();
    }

    private function fakeEvolutionSemInstancia(): void
    {
        Http::fake([
            '*/instance/fetchInstances*' => Http::response([], 200),
            '*/instance/create*' => Http::response(['ok' => true], 201),
            '*/webhook/find/*' => Http::response([], 404),
            '*/webhook/set/*' => Http::response(['ok' => true], 200),
        ]);
    }

    // ---- provisionamento -----------------------------------------------------------

    public function test_provisionamento_cria_canal_instancia_e_webhook_por_token(): void
    {
        $this->fakeEvolutionSemInstancia();
        $conta = Account::create(['name' => 'Oficina do Ze']);

        $canal = app(ChannelProvisioner::class)->provision($conta);

        // Canal: instancia unica por conta, token, credenciais CIFRADAS (do env default).
        $this->assertSame('conta-' . $conta->id . '-oficina-do-ze', $canal->instance);
        $this->assertSame('evolution', $canal->provider);
        $this->assertSame(48, strlen((string) $canal->webhook_token));
        $this->assertSame('chave-env', $canal->credentials['apikey']);
        $cru = (string) \Illuminate\Support\Facades\DB::table('channels')->where('id', $canal->id)->value('credentials');
        $this->assertStringNotContainsString('chave-env', $cru); // cifrado em repouso

        // Evolution: criou a instancia e apontou o webhook pra ROTA POR TOKEN.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/instance/create')
            && $r['instanceName'] === $canal->instance);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/webhook/set/' . $canal->instance)
            && data_get($r->data(), 'webhook.url') === 'http://app-host:8190/webhook/evolution/' . $canal->webhook_token);

        // Idempotente: segunda chamada reusa o canal (nada duplicado).
        $canal2 = app(ChannelProvisioner::class)->provision($conta);
        $this->assertSame($canal->id, $canal2->id);
        $this->assertSame(1, Channel::withoutAccountScope()->where('account_id', $conta->id)->count());
    }

    public function test_provisionamento_nao_toca_webhook_vivo_divergente(): void
    {
        Http::fake([
            '*/instance/fetchInstances*' => Http::response([['name' => 'ja-existe']], 200),
            // Instancia VIVA apontando pra URL legada (a da conta 1 em producao).
            '*/webhook/find/*' => Http::response(['url' => 'http://app-host:8190/webhook/evolution'], 200),
            '*/webhook/set/*' => Http::response(['ok' => true], 200),
        ]);
        $conta = Account::create(['name' => 'Conta Viva']);
        Channel::withoutAccountScope()->create([
            'account_id' => $conta->id, 'instance' => 'ja-existe', 'provider' => 'evolution',
            'webhook_token' => str_repeat('t', 48), 'status' => 'connected',
        ]);

        app(ChannelProvisioner::class)->provision($conta);

        // Webhook divergente INTOCADO (a troca e o migrate com gate) — e nada de create.
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/webhook/set/'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/instance/create'));
    }

    // ---- telas por conta ------------------------------------------------------------------

    public function test_status_conexao_opera_no_canal_da_conta_com_credenciais_do_canal(): void
    {
        $a = Account::create(['name' => 'A']);
        $b = Account::create(['name' => 'B']);
        $canalA = Channel::withoutAccountScope()->create(['account_id' => $a->id, 'instance' => 'inst-a', 'status' => 'connected',
            'credentials' => ['base_url' => 'http://a-host:1111', 'apikey' => 'chave-a', 'instance' => 'inst-a']]);
        $canalB = Channel::withoutAccountScope()->create(['account_id' => $b->id, 'instance' => 'inst-b', 'status' => 'connecting',
            'credentials' => ['base_url' => 'http://b-host:2222', 'apikey' => 'chave-b', 'instance' => 'inst-b']]);

        Http::fake(['http://b-host:2222/*' => Http::response(['instance' => ['state' => 'open']], 200)]);
        app(AccountContext::class)->set($b->id);

        Livewire::test(StatusConexao::class)->call('refresh')->assertSet('state', 'open');

        // Consultou o host DO CANAL B (credenciais do canal, nao do env)...
        Http::assertSent(fn ($r) => str_contains($r->url(), 'b-host:2222/instance/connectionState/inst-b')
            && $r->header('apikey')[0] === 'chave-b');
        // ...e sincronizou SO o canal da conta B (o da A intocado).
        $this->assertSame('connected', $canalB->fresh()->status);
        $this->assertSame('connected', $canalA->fresh()->status);
        $this->assertSame('connected', $canalA->fresh()->status); // A segue como estava
    }

    public function test_gate_de_conexao_por_conta(): void
    {
        config(['tenancy.single_account_fallback' => false]);
        $a = Account::create(['name' => 'A']);
        $b = Account::create(['name' => 'B']);
        Channel::withoutAccountScope()->create(['account_id' => $a->id, 'instance' => 'inst-a', 'status' => 'disconnected']);
        Channel::withoutAccountScope()->create(['account_id' => $b->id, 'instance' => 'inst-b', 'status' => 'connected']);
        foreach ([$a, $b] as $acc) {
            AutoReplySetting::withoutAccountScope()->firstOrCreate(['account_id' => $acc->id]);
        }
        $userA = User::create(['name' => 'A', 'email' => 'a@x.local', 'password' => Hash::make('senha-forte-123')]);
        $userB = User::create(['name' => 'B', 'email' => 'b@x.local', 'password' => Hash::make('senha-forte-123')]);
        $userA->accounts()->attach($a->id, ['role' => 'owner']);
        $userB->accounts()->attach($b->id, ['role' => 'owner']);

        // Conta A desconectada: cai na tela de conexao. Conta B: navega normal.
        $this->actingAs($userA)->get('/contatos')->assertRedirect(route('conexao'));
        $this->actingAs($userB)->get('/contatos')->assertOk();
    }

    // ---- comandos ------------------------------------------------------------------------------

    public function test_sync_env_preenche_credenciais_uma_vez_e_e_idempotente(): void
    {
        $conta = Account::create(['name' => 'T']);
        $canal = Channel::withoutAccountScope()->create(['account_id' => $conta->id, 'instance' => 'inst-t', 'status' => 'connected']);
        $this->assertEmpty($canal->credentials);

        $this->artisan('msg:channel:sync-env', ['--account' => $conta->id])->assertSuccessful();
        $canal->refresh();
        $this->assertSame('chave-env', $canal->credentials['apikey']);
        $this->assertSame('inst-t', $canal->credentials['instance']);

        // Idempotente: nao sobrescreve (mesmo que o env mude depois).
        config(['services.evolution.api_key' => 'OUTRA-CHAVE']);
        $this->artisan('msg:channel:sync-env', ['--account' => $conta->id])
            ->expectsOutputToContain('JA preenchidas')->assertSuccessful();
        $this->assertSame('chave-env', $canal->fresh()->credentials['apikey']);
    }

    public function test_webhook_migrate_dry_run_nao_muda_e_apply_aponta_pro_token(): void
    {
        Http::fake([
            '*/webhook/find/*' => Http::response(['url' => 'http://app-host:8190/webhook/evolution'], 200),
            '*/webhook/set/*' => Http::response(['ok' => true], 200),
        ]);
        $conta = Account::create(['name' => 'T']);
        $canal = Channel::withoutAccountScope()->create([
            'account_id' => $conta->id, 'instance' => 'inst-t', 'status' => 'connected',
            'webhook_token' => str_repeat('k', 48),
        ]);

        // DRY-RUN (default): mostra o plano, NAO chama webhook/set, token mascarado.
        $this->artisan('evolution:webhook:migrate', ['--account' => $conta->id])
            ->expectsOutputToContain('DRY-RUN')->assertSuccessful();
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/webhook/set/'));

        // --apply: aponta pra rota por token, sem header de secret.
        $this->artisan('evolution:webhook:migrate', ['--account' => $conta->id, '--apply' => true])
            ->assertSuccessful();
        Http::assertSent(fn ($r) => str_contains($r->url(), '/webhook/set/inst-t')
            && data_get($r->data(), 'webhook.url') === 'http://app-host:8190/webhook/evolution/' . $canal->webhook_token
            // headers vao como OBJETO json vazio ("{}") — exigencia da Evolution v2.3.7.
            && (array) data_get($r->data(), 'webhook.headers') === []
            && str_contains((string) $r->body(), '"headers":{}'));
    }
}
