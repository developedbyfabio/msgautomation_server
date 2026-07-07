<?php

namespace Tests\Feature;

use App\Livewire\Servidores\Inventario;
use App\Models\Account;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\Secret;
use App\Models\User;
use App\Servers\Server;
use App\Tenancy\AccountContext;
use App\Whatsapp\Secrets\SecretVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Servidores S1 — CRUD do inventario + ciclo do token. Area OWNER-only
 * (ferramenta interna do dono): rota 403 pro operador, item some do menu,
 * acao Livewire forjada barrada pelo gate. Token: gerado na criacao, claro
 * SO no Cofre (referencia na tabela), exibido uma vez, regeneravel (o antigo
 * passa a dar 401 na ingestao).
 */
class ServersInventarioTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private User $operador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'Interna']);
        Channel::create(['account_id' => $this->account->id, 'instance' => 'inst-a', 'provider' => 'evolution', 'webhook_token' => 'tok-a', 'status' => 'connected']);
        AutoReplySetting::create(['account_id' => $this->account->id]);

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

    private function payloadValido(): array
    {
        return [
            'cpu_pct' => 10,
            'mem' => ['pct' => 20],
            'disks' => [['mount' => '/', 'pct' => 30]],
        ];
    }

    // ---- acesso (owner-only) --------------------------------------------------

    public function test_owner_acessa_a_pagina(): void
    {
        $this->actingAs($this->owner)->withSession(['tenancy.account_id' => $this->account->id])
            ->get(route('servidores'))
            ->assertOk()
            ->assertSee('Novo servidor');
    }

    public function test_operador_recebe_403_na_rota(): void
    {
        $this->actingAs($this->operador)->withSession(['tenancy.account_id' => $this->account->id])
            ->get(route('servidores'))
            ->assertForbidden();
    }

    public function test_item_do_menu_some_para_operador_e_aparece_para_owner(): void
    {
        // /perfil e acessivel aos dois papeis — bom lugar pra inspecionar o menu.
        $this->actingAs($this->owner)->withSession(['tenancy.account_id' => $this->account->id])
            ->get(route('perfil'))->assertSee(route('servidores'));

        $this->actingAs($this->operador)->withSession(['tenancy.account_id' => $this->account->id])
            ->get(route('perfil'))->assertDontSee(route('servidores'));
    }

    public function test_acao_livewire_forjada_de_operador_e_barrada(): void
    {
        app(AccountContext::class)->set($this->account->id);
        $this->actingAs($this->operador);

        Livewire::test(Inventario::class)
            ->call('novo')
            ->set('name', 'Forjado')
            ->set('os', 'linux')
            ->call('save')
            ->assertForbidden();

        $this->assertSame(0, Server::withoutAccountScope()->count());
    }

    // ---- criacao + token no Cofre ----------------------------------------------

    public function test_criar_servidor_gera_token_no_cofre_e_exibe_uma_vez(): void
    {
        $this->comoOwner();

        $comp = Livewire::test(Inventario::class)
            ->call('novo')
            ->set('name', 'Servidor ERP')
            ->set('host', '192.168.11.20')
            ->set('os', 'linux')
            ->set('grupo', 'producao')
            ->call('save')
            ->assertHasNoErrors();

        $server = Server::withoutAccountScope()->where('name', 'Servidor ERP')->first();
        $this->assertNotNull($server);

        // Token exibido UMA vez no componente...
        $plain = $comp->get('plainToken');
        $this->assertNotNull($plain);
        $this->assertStringStartsWith('agt_', $plain);

        // ...tabela guarda REFERENCIA + hash (nunca o claro)...
        $this->assertSame('agente-servidor-'.$server->id, $server->agent_token_secret_ref);
        $this->assertSame(hash('sha256', $plain), $server->agent_token_hash);
        $this->assertStringNotContainsString($plain, json_encode($server->getAttributes()));

        // ...e o claro vive no Cofre (cifra dedicada), recuperavel pelo vault.
        $this->assertSame($plain, app(SecretVault::class)->reveal($this->account->id, $server->agent_token_secret_ref));

        // Fechar o modal descarta o claro do estado.
        $comp->call('dismissToken')->assertSet('plainToken', null);

        // Fim a fim: o token gerado autentica a ingestao.
        $this->postJson(route('webhook.servers.ingest'), $this->payloadValido(), ['X-Agent-Token' => $plain])
            ->assertOk();
    }

    public function test_regenerar_token_invalida_o_anterior(): void
    {
        $this->comoOwner();

        $comp = Livewire::test(Inventario::class)
            ->call('novo')->set('name', 'Srv')->set('os', 'linux')->call('save');
        $antigo = $comp->get('plainToken');
        $server = Server::withoutAccountScope()->where('name', 'Srv')->first();

        $novo = $comp->call('dismissToken')
            ->call('askRegenerate', $server->id)
            ->call('regenerateConfirmed')
            ->get('plainToken');

        $this->assertNotNull($novo);
        $this->assertNotSame($antigo, $novo);

        // O antigo morreu na hora; o novo autentica.
        $this->postJson(route('webhook.servers.ingest'), $this->payloadValido(), ['X-Agent-Token' => $antigo])
            ->assertStatus(401);
        $this->postJson(route('webhook.servers.ingest'), $this->payloadValido(), ['X-Agent-Token' => $novo])
            ->assertOk();

        // O Cofre guarda SO o valor novo (mesmo nome de segredo, valor substituido).
        $this->assertSame($novo, app(SecretVault::class)->reveal($this->account->id, $server->agent_token_secret_ref));
    }

    // ---- validacao / edicao / exclusao ------------------------------------------

    public function test_so_linux_na_v1(): void
    {
        $this->comoOwner();

        Livewire::test(Inventario::class)
            ->call('novo')
            ->set('name', 'Win')
            ->set('os', 'windows')
            ->call('save')
            ->assertHasErrors(['os']);

        $this->assertSame(0, Server::withoutAccountScope()->count());
    }

    public function test_nome_duplicado_na_conta_e_rejeitado(): void
    {
        $this->comoOwner();
        Server::create(['account_id' => $this->account->id, 'name' => 'Duplicado', 'os' => 'linux']);

        Livewire::test(Inventario::class)
            ->call('novo')->set('name', 'Duplicado')->set('os', 'linux')->call('save')
            ->assertHasErrors(['name']);
    }

    public function test_editar_atualiza_campos_sem_tocar_no_token(): void
    {
        $this->comoOwner();
        $comp = Livewire::test(Inventario::class)
            ->call('novo')->set('name', 'Antes')->set('os', 'linux')->call('save');
        $server = Server::withoutAccountScope()->where('name', 'Antes')->first();
        $hashAntes = $server->agent_token_hash;

        $comp->call('dismissToken')
            ->call('edit', $server->id)
            ->set('name', 'Depois')
            ->set('grupo', 'teste')
            ->call('save')
            ->assertHasNoErrors();

        $server->refresh();
        $this->assertSame('Depois', $server->name);
        $this->assertSame('teste', $server->grupo);
        $this->assertSame($hashAntes, $server->agent_token_hash); // token intacto
    }

    public function test_excluir_remove_servidor_e_segredo_do_cofre(): void
    {
        $this->comoOwner();
        $comp = Livewire::test(Inventario::class)
            ->call('novo')->set('name', 'Efemero')->set('os', 'linux')->call('save');
        $server = Server::withoutAccountScope()->where('name', 'Efemero')->first();
        $ref = $server->agent_token_secret_ref;
        $this->assertSame(1, Secret::withoutAccountScope()->where('nome', $ref)->count());

        $comp->call('dismissToken')
            ->call('confirmDelete', $server->id)
            ->call('deleteConfirmed');

        $this->assertSame(0, Server::withoutAccountScope()->count());
        $this->assertSame(0, Secret::withoutAccountScope()->where('nome', $ref)->count()); // sem token orfao
    }

    public function test_desativar_ingestao_da_403_e_reativar_volta(): void
    {
        $this->comoOwner();
        $comp = Livewire::test(Inventario::class)
            ->call('novo')->set('name', 'Liga-desliga')->set('os', 'linux')->call('save');
        $plain = $comp->get('plainToken');
        $server = Server::withoutAccountScope()->where('name', 'Liga-desliga')->first();

        $comp->call('dismissToken')->call('toggleEnabled', $server->id);
        $this->postJson(route('webhook.servers.ingest'), $this->payloadValido(), ['X-Agent-Token' => $plain])
            ->assertStatus(403);

        $comp->call('toggleEnabled', $server->id);
        $this->postJson(route('webhook.servers.ingest'), $this->payloadValido(), ['X-Agent-Token' => $plain])
            ->assertOk();
    }
}
