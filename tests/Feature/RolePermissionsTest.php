<?php

namespace Tests\Feature;

use App\Livewire\Admin\Tenants;
use App\Livewire\Configuracoes;
use App\Livewire\Senhas;
use App\Models\Account;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\Secret;
use App\Models\User;
use App\Tenancy\AccountContext;
use App\Whatsapp\Secrets\SecretVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fatia 22 — perfis e permissoes com enforcement SERVER-SIDE: rotas owner-only
 * barram operador por URL (403 — sumir do menu nao protege) e as acoes Livewire
 * sensiveis rejeitam no servidor (forjaveis). Papel e POR CONTA; super-admin e
 * ortogonal; fail-safes de ultimo owner ja existiam no Admin\Tenants (cobertos
 * la) — aqui entra o lado "auto-rebaixamento COM outro owner e permitido".
 */
class RolePermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;
    private User $owner;
    private User $operador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'A']);
        Channel::create(['account_id' => $this->account->id, 'instance' => 'inst-a', 'provider' => 'evolution', 'webhook_token' => 'tok-a', 'status' => 'connected']);
        AutoReplySetting::create(['account_id' => $this->account->id]);

        $this->owner = User::create(['name' => 'Dono', 'email' => 'dono@x.local', 'password' => Hash::make('senha-forte-123')]);
        $this->owner->accounts()->attach($this->account->id, ['role' => 'owner']);
        $this->operador = User::create(['name' => 'Sec', 'email' => 'sec@x.local', 'password' => Hash::make('senha-forte-123')]);
        $this->operador->accounts()->attach($this->account->id, ['role' => 'operador']);
    }

    private function como(User $u)
    {
        return $this->actingAs($u)->withSession(['tenancy.account_id' => $this->account->id]);
    }

    // ---- Enforcement de ROTA (o que importa) -----------------------------------

    public function test_operador_barrado_por_url_nas_areas_owner(): void
    {
        // Fatia 23 (ajuste deliberado): campanhas/conhecimento sairam desta lista
        // — operador passou a VER (decisao do dono); a escrita segue barrada por
        // gate (ViewOnlyNavigationTest cobre). Estruturais seguem owner-only.
        foreach (['configuracoes', 'senhas', 'logs', 'regras', 'fluxos', 'variaveis'] as $rota) {
            $this->como($this->operador)->get(route($rota))->assertForbidden();
        }
    }

    public function test_operador_acessa_a_operacao_do_dia_a_dia(): void
    {
        // Fatia 23: + campanhas/conhecimento em modo leitura.
        foreach (['painel', 'conversas', 'kanban', 'contatos', 'revisao', 'perfil', 'conexao', 'campanhas', 'conhecimento'] as $rota) {
            $this->como($this->operador)->get(route($rota))->assertOk();
        }
    }

    public function test_owner_acessa_tudo_da_conta(): void
    {
        foreach (['configuracoes', 'senhas', 'logs', 'regras', 'fluxos', 'conversas', 'kanban'] as $rota) {
            $this->como($this->owner)->get(route($rota))->assertOk();
        }
    }

    public function test_operador_e_owner_nao_acessam_admin(): void
    {
        $this->como($this->operador)->get(route('admin.tenants'))->assertForbidden();
        $this->como($this->owner)->get(route('admin.tenants'))->assertForbidden(); // owner de conta != super-admin
    }

    public function test_super_admin_bypassa_o_papel_de_conta(): void
    {
        // Super-admin vinculado como mero OPERADOR: acessa area owner mesmo assim
        // (is_platform_admin e ortogonal ao papel de conta).
        $admin = User::create(['name' => 'Root', 'email' => 'root@x.local', 'password' => Hash::make('senha-forte-123')]);
        $admin->forceFill(['is_platform_admin' => true])->save();
        $admin->accounts()->attach($this->account->id, ['role' => 'operador']);

        $this->actingAs($admin)->withSession(['tenancy.account_id' => $this->account->id])
            ->get(route('configuracoes'))->assertOk();
    }

    // ---- Enforcement de ACAO (forjabilidade) -------------------------------------

    public function test_acao_forjada_revelar_cofre_por_operador_e_rejeitada(): void
    {
        app(AccountContext::class)->set($this->account->id);
        app(SecretVault::class)->put($this->account->id, 'wifi', 'ValorSecreto9');
        $id = Secret::withoutAccountScope()->where('nome', 'wifi')->value('id');

        $this->actingAs($this->operador);
        // abort(403) na ACTION vira status da resposta do componente (forjada = barrada).
        Livewire::test(Senhas::class)
            ->call('askReveal', $id)
            ->set('revealPassword', 'senha-forte-123') // ate com a senha de login CORRETA
            ->call('confirmReveal')
            ->assertForbidden()
            ->assertSet('revealedValue', null); // o valor NUNCA entrou no componente
    }

    public function test_acao_forjada_salvar_config_por_operador_e_rejeitada_sem_persistir(): void
    {
        app(AccountContext::class)->set($this->account->id);
        $capAntes = (int) AutoReplySetting::withoutAccountScope()->where('account_id', $this->account->id)->value('per_day_cap');

        $this->actingAs($this->operador);
        Livewire::test(Configuracoes::class)->set('per_day_cap', 999)->call('save')->assertForbidden();

        $this->assertSame($capAntes, (int) AutoReplySetting::withoutAccountScope()->where('account_id', $this->account->id)->value('per_day_cap'));
    }

    // ---- Papel e POR CONTA ---------------------------------------------------------

    public function test_papel_e_por_conta_owner_em_a_operador_em_b(): void
    {
        $b = Account::create(['name' => 'B']);
        Channel::create(['account_id' => $b->id, 'instance' => 'inst-b', 'provider' => 'evolution', 'webhook_token' => 'tok-b', 'status' => 'connected']);
        $this->owner->accounts()->attach($b->id, ['role' => 'operador']); // owner de A, mero operador em B

        // Conta ativa = A: acessa configuracoes (owner la).
        $this->actingAs($this->owner)->withSession(['tenancy.account_id' => $this->account->id])
            ->get(route('configuracoes'))->assertOk();

        // Conta ativa = B: barrado (papel nao vaza entre contas).
        $this->actingAs($this->owner)->withSession(['tenancy.account_id' => $b->id])
            ->get(route('configuracoes'))->assertForbidden();
    }

    // ---- Menu (cosmetico, por cima do enforcement) ---------------------------------

    public function test_menu_oculta_areas_owner_para_operador_e_mostra_para_owner(): void
    {
        // Rotulos SEM homonimo no conteudo/header ('Configuracoes' aparece num
        // link de hint do header do robo — legitimo: a ROTA protege, clicar da 403).
        // Fatia 23 (ajuste deliberado): rotulos de negocio; conhecimento agora
        // VISIVEL pro operador (view-only) — a ocultacao vale pros estruturais.
        $resp = $this->como($this->operador)->get(route('kanban'))->assertOk();
        $resp->assertDontSee('Variaveis');
        $resp->assertDontSee('Respostas automaticas');
        $resp->assertDontSee('Menus de atendimento');
        $resp->assertSee('Clientes');                 // dia a dia continua no menu
        $resp->assertSee('Informacoes do negocio');   // view-only VISIVEL

        $ok = $this->como($this->owner)->get(route('kanban'))->assertOk();
        $ok->assertSee('Variaveis');
        $ok->assertSee('Respostas automaticas');
    }

    // ---- Fail-safe: auto-rebaixamento COM outro owner (o lado que faltava) ---------

    public function test_rebaixar_owner_com_outro_owner_presente_e_permitido(): void
    {
        $admin = User::create(['name' => 'Root', 'email' => 'root2@x.local', 'password' => Hash::make('senha-forte-123')]);
        $admin->forceFill(['is_platform_admin' => true])->save();
        $segundo = User::create(['name' => 'Socio', 'email' => 'socio@x.local', 'password' => Hash::make('senha-forte-123')]);
        $segundo->accounts()->attach($this->account->id, ['role' => 'owner']); // 2 owners agora

        $this->actingAs($admin);
        Livewire::test(Tenants::class)
            ->call('openEdit', $this->account->id)
            ->call('alternarOwner', $this->owner->id); // rebaixa UM deles: permitido

        $this->assertSame('operador', $this->owner->fresh()->roleIn($this->account->id));
        $this->assertSame('owner', $segundo->fresh()->roleIn($this->account->id));
        // (Bloqueio do ULTIMO owner ja coberto no AdminTenantsTest — referenciado.)
    }

    // ---- Backfill -------------------------------------------------------------------

    public function test_backfill_promove_e_e_idempotente(): void
    {
        // NOTA (registrada): account_user.role e NOT NULL no schema — "papel null"
        // e impossivel; o trabalho REAL do backfill e a promocao de conta sem
        // owner (+ o saneamento de string vazia, possivel no schema).
        $c = Account::create(['name' => 'Legada']);
        $u1 = User::create(['name' => 'U1', 'email' => 'u1@x.local', 'password' => Hash::make('senha-forte-123')]);
        $u2 = User::create(['name' => 'U2', 'email' => 'u2@x.local', 'password' => Hash::make('senha-forte-123')]);
        DB::table('account_user')->insert([
            ['account_id' => $c->id, 'user_id' => $u1->id, 'role' => '', 'created_at' => now(), 'updated_at' => now()],
            ['account_id' => $c->id, 'user_id' => $u2->id, 'role' => 'operador', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->artisan('msg:backfill-roles')->assertSuccessful();

        // Papel vazio saneado -> operador; conta sem owner -> o vinculo mais
        // ANTIGO promovido a owner (nunca conta orfa).
        $this->assertSame('owner', $u1->fresh()->roleIn($c->id));
        $this->assertSame('operador', $u2->fresh()->roleIn($c->id));

        // Idempotencia: 2a execucao nao muda nada.
        $antes = DB::table('account_user')->orderBy('id')->pluck('role')->all();
        $this->artisan('msg:backfill-roles')->assertSuccessful();
        $this->assertSame($antes, DB::table('account_user')->orderBy('id')->pluck('role')->all());
    }
}
