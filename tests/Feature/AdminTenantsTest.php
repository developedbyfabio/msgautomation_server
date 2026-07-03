<?php

namespace Tests\Feature;

use App\Livewire\Admin\Tenants;
use App\Models\Account;
use App\Models\Board;
use App\Models\Contact;
use App\Models\User;
use App\Models\Variable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 22 — administracao de tenants (super-admin). Gate por is_platform_admin;
 * cria Account + owner (transversais); nenhum dado escopado exposto/tocado.
 */
class AdminTenantsTest extends TestCase
{
    use RefreshDatabase;

    private function conta(string $nome = 'Tenant Base'): Account
    {
        return Account::create(['name' => $nome]);
    }

    private function usuario(Account $conta, bool $admin = false, string $email = 'u@x.local'): User
    {
        $u = User::create(['name' => 'U', 'email' => $email, 'password' => Hash::make('senha-forte-123')]);
        $u->accounts()->syncWithoutDetaching([$conta->id => ['role' => 'owner']]);
        if ($admin) {
            $u->forceFill(['is_platform_admin' => true])->save(); // fora do fillable de proposito
        }

        return $u;
    }

    // ---- 1. acesso -------------------------------------------------------------

    public function test_super_admin_acessa_e_comum_recebe_403(): void
    {
        $conta = $this->conta();
        $admin = $this->usuario($conta, admin: true, email: 'admin@plataforma.local');
        $comum = $this->usuario($conta, admin: false, email: 'comum@cliente.local');

        // Prompt 29: super-admin precisa de 2FA pra acessar /admin/* — habilita.
        $admin->forceFill(['two_factor_secret' => encrypt('ADMIN2FA'), 'two_factor_confirmed_at' => now()])->save();

        // Deslogado PRIMEIRO (actingAs persiste a auth no resto do teste).
        $this->get('/admin/tenants')->assertRedirect(route('login'));      // deslogado
        $this->actingAs($comum)->get('/admin/tenants')->assertForbidden(); // 403 (logado, nao-admin)
        $this->actingAs($admin)->get('/admin/tenants')->assertOk();        // super-admin COM 2FA
    }

    // ---- 2. criar tenant -------------------------------------------------------

    public function test_criar_tenant_gera_account_com_board_variaveis_e_owner_vinculado(): void
    {
        $admin = $this->usuario($this->conta(), admin: true, email: 'admin@plataforma.local');

        Livewire::actingAs($admin)->test(Tenants::class)
            ->call('openCreate')
            ->set('accountName', 'Padaria do Ze')
            ->set('ownerName', 'Jose')
            ->set('ownerEmail', 'ze@padaria.com')
            ->set('ownerPassword', 'senha-do-ze-123')
            ->call('criar')
            ->assertHasNoErrors();

        $nova = Account::where('name', 'Padaria do Ze')->first();
        $this->assertNotNull($nova);
        // booted(): board default + variaveis de sistema (id explicito, sem contexto)
        $this->assertTrue(Board::withoutAccountScope()->where('account_id', $nova->id)->where('is_default', true)->exists());
        $this->assertTrue(Variable::withoutAccountScope()->where('account_id', $nova->id)->exists());
        // owner vinculado, senha hasheada (nao em claro)
        $owner = User::where('email', 'ze@padaria.com')->first();
        $this->assertNotNull($owner);
        $this->assertTrue(Hash::check('senha-do-ze-123', $owner->password));
        $this->assertNotSame('senha-do-ze-123', $owner->password);
        $this->assertSame('owner', $owner->accounts()->where('accounts.id', $nova->id)->first()->pivot->role);
        $this->assertFalse((bool) $owner->is_platform_admin); // owner de tenant != super-admin
    }

    // ---- 3. validacao ----------------------------------------------------------

    public function test_conta_e_email_duplicados_sao_rejeitados(): void
    {
        $base = $this->conta('Conta Existente');
        $admin = $this->usuario($base, admin: true, email: 'admin@plataforma.local');

        // nome de conta duplicado
        Livewire::actingAs($admin)->test(Tenants::class)
            ->set('accountName', 'Conta Existente')->set('ownerName', 'X')
            ->set('ownerEmail', 'novo@x.com')->set('ownerPassword', 'senha-forte-123')
            ->call('criar')->assertHasErrors(['accountName']);

        // email de owner duplicado (admin@plataforma.local ja existe)
        Livewire::actingAs($admin)->test(Tenants::class)
            ->set('accountName', 'Conta Nova Unica')->set('ownerName', 'X')
            ->set('ownerEmail', 'admin@plataforma.local')->set('ownerPassword', 'senha-forte-123')
            ->call('criar')->assertHasErrors(['ownerEmail']);

        // senha curta
        Livewire::actingAs($admin)->test(Tenants::class)
            ->set('accountName', 'Outra Conta')->set('ownerName', 'X')
            ->set('ownerEmail', 'ok@x.com')->set('ownerPassword', 'curta')
            ->call('criar')->assertHasErrors(['ownerPassword']);
    }

    // ---- 4. owner cai na conta dele --------------------------------------------

    public function test_owner_criado_fica_vinculado_so_a_propria_conta(): void
    {
        $base = $this->conta('Conta do Fabio');
        $admin = $this->usuario($base, admin: true, email: 'admin@plataforma.local');

        Livewire::actingAs($admin)->test(Tenants::class)
            ->set('accountName', 'Cliente Novo')->set('ownerName', 'Dono')
            ->set('ownerEmail', 'dono@cliente.com')->set('ownerPassword', 'senha-forte-123')
            ->call('criar');

        $nova = Account::where('name', 'Cliente Novo')->first();
        $owner = User::where('email', 'dono@cliente.com')->first();
        // Vinculo EXATO: so a conta nova (nao a do Fabio nem outra).
        $this->assertSame([$nova->id], $owner->accounts()->pluck('accounts.id')->all());
    }

    // ---- 5. isolamento ---------------------------------------------------------

    public function test_criar_segundo_tenant_nao_vaza_dados_pro_primeiro(): void
    {
        $base = $this->conta('Base');
        $admin = $this->usuario($base, admin: true, email: 'admin@plataforma.local');

        // Primeiro tenant + um dado escopado dele.
        Livewire::actingAs($admin)->test(Tenants::class)
            ->set('accountName', 'Tenant A')->set('ownerName', 'A')
            ->set('ownerEmail', 'a@a.com')->set('ownerPassword', 'senha-forte-123')->call('criar');
        $a = Account::where('name', 'Tenant A')->first();
        Contact::create(['account_id' => $a->id, 'remote_jid' => '5541999990000@s.whatsapp.net', 'auto_reply_mode' => 'default']);

        // Segundo tenant.
        Livewire::actingAs($admin)->test(Tenants::class)
            ->set('accountName', 'Tenant B')->set('ownerName', 'B')
            ->set('ownerEmail', 'b@b.com')->set('ownerPassword', 'senha-forte-123')->call('criar');
        $b = Account::where('name', 'Tenant B')->first();

        // B nasce SEM contatos de A (nada vazou); A intacto.
        $this->assertSame(0, Contact::withoutAccountScope()->where('account_id', $b->id)->count());
        $this->assertSame(1, Contact::withoutAccountScope()->where('account_id', $a->id)->count());

        // A tela lista as contas (transversal) mas NAO expoe dado escopado (numero do contato).
        Livewire::actingAs($admin)->test(Tenants::class)
            ->assertSee('Tenant A')->assertSee('Tenant B')
            ->assertDontSee('5541999990000');
    }

    // ---- Prompt 25: editar conta + gerir usuarios ------------------------------

    private function admin(): User
    {
        return $this->usuario($this->conta('Base Admin'), admin: true, email: 'admin@plat.local');
    }

    public function test_renomear_tenant_nao_muda_o_slug_instancia(): void
    {
        $admin = $this->admin();
        $t = Account::create(['name' => 'Antigo']);
        $canal = \App\Models\Channel::create([
            'account_id' => $t->id, 'instance' => 'conta-' . $t->id . '-antigo', 'provider' => 'evolution',
            'webhook_token' => 'tok', 'status' => 'disconnected',
        ]);

        \Livewire\Livewire::actingAs($admin)->test(Tenants::class)
            ->call('openEdit', $t->id)
            ->set('editName', 'Nome Novo')
            ->call('salvarConta')->assertHasNoErrors();

        $this->assertSame('Nome Novo', $t->fresh()->name);
        $this->assertSame('conta-' . $t->id . '-antigo', $canal->fresh()->instance); // slug/instancia imutavel
    }

    public function test_adicionar_owner_a_tenant_sem_usuarios(): void
    {
        $admin = $this->admin();
        $t = Account::create(['name' => 'T']); // 0 usuarios (o caso orfao)
        $this->assertSame(0, $t->users()->count());

        \Livewire\Livewire::actingAs($admin)->test(Tenants::class)
            ->call('openEdit', $t->id)
            ->set('nuName', 'Dono do T')->set('nuEmail', 'dono@t.com')
            ->set('nuPassword', 'senha-forte-123')->set('nuOwner', true)
            ->call('adicionarUsuario')->assertHasNoErrors();

        $owner = User::where('email', 'dono@t.com')->firstOrFail();
        $this->assertTrue(Hash::check('senha-forte-123', $owner->password)); // hasheada
        $this->assertFalse((bool) $owner->is_platform_admin);                 // nao vira super-admin
        $this->assertSame(1, $t->fresh()->users()->count());
        $this->assertSame('owner', $t->users()->where('users.id', $owner->id)->first()->pivot->role);
    }

    public function test_editar_email_e_resetar_senha(): void
    {
        $admin = $this->admin();
        $t = Account::create(['name' => 'Tenant U']);
        $u = User::create(['name' => 'User', 'email' => 'velho@t.com', 'password' => Hash::make('senha-antiga-1')]);
        $u->accounts()->attach($t->id, ['role' => 'operador']);

        $tela = \Livewire\Livewire::actingAs($admin)->test(Tenants::class)->call('openEdit', $t->id);

        // editar email
        $tela->set('rowUserId', $u->id)->set('rowEmail', 'novo@t.com')->call('editarEmail', $u->id)->assertHasNoErrors();
        $this->assertSame('novo@t.com', $u->fresh()->email);

        // resetar senha (nova funciona, antiga nao)
        $tela->set('rowUserId', $u->id)->set('rowPassword', 'senha-nova-123')->call('resetarSenha', $u->id)->assertHasNoErrors();
        $fresh = $u->fresh();
        $this->assertTrue(Hash::check('senha-nova-123', $fresh->password));
        $this->assertFalse(Hash::check('senha-antiga-1', $fresh->password));
    }

    public function test_remover_usuario_e_bloqueio_do_ultimo_owner(): void
    {
        $admin = $this->admin();
        $t = Account::create(['name' => 'Tenant R']);
        $owner = User::create(['name' => 'O', 'email' => 'o@t.com', 'password' => Hash::make('x-123456789')]);
        $owner->accounts()->attach($t->id, ['role' => 'owner']);
        $op = User::create(['name' => 'P', 'email' => 'p@t.com', 'password' => Hash::make('x-123456789')]);
        $op->accounts()->attach($t->id, ['role' => 'operador']);

        $tela = \Livewire\Livewire::actingAs($admin)->test(Tenants::class)->call('openEdit', $t->id);

        // remove operador: ok
        $tela->call('removerUsuario', $op->id);
        $this->assertSame(0, $t->users()->where('users.id', $op->id)->count());

        // remove o ULTIMO owner: bloqueado (segue vinculado)
        $tela->call('removerUsuario', $owner->id);
        $this->assertSame(1, $t->users()->where('users.id', $owner->id)->count());
        $this->assertSame(1, $this->ownersDe($t));
    }

    public function test_nao_rebaixar_o_ultimo_owner(): void
    {
        $admin = $this->admin();
        $t = Account::create(['name' => 'Tenant Owner']);
        $owner = User::create(['name' => 'O', 'email' => 'o@to.com', 'password' => Hash::make('x-123456789')]);
        $owner->accounts()->attach($t->id, ['role' => 'owner']);

        \Livewire\Livewire::actingAs($admin)->test(Tenants::class)
            ->call('openEdit', $t->id)
            ->call('alternarOwner', $owner->id); // tentar rebaixar o unico owner

        $this->assertSame('owner', $t->users()->where('users.id', $owner->id)->first()->pivot->role); // segue owner
    }

    public function test_gestao_de_usuarios_de_a_nao_afeta_b(): void
    {
        $admin = $this->admin();
        $a = Account::create(['name' => 'Tenant A']);
        $b = Account::create(['name' => 'Tenant B']);
        $ub = User::create(['name' => 'B User', 'email' => 'b@b.com', 'password' => Hash::make('x-123456789')]);
        $ub->accounts()->attach($b->id, ['role' => 'owner']);

        // Editando A: adiciona usuario, renomeia. B nao pode ser tocado.
        \Livewire\Livewire::actingAs($admin)->test(Tenants::class)
            ->call('openEdit', $a->id)
            ->set('editName', 'A Renomeada')->call('salvarConta')
            ->set('nuName', 'A User')->set('nuEmail', 'a@a.com')->set('nuPassword', 'senha-forte-123')
            ->call('adicionarUsuario')
            // a lista de usuarios em edicao (A) NAO mostra o usuario da B
            ->assertDontSee('b@b.com');

        $this->assertSame('Tenant B', $b->fresh()->name);         // B intacto
        $this->assertSame(1, $b->users()->count());               // so o dele
        $this->assertSame('b@b.com', $b->users()->first()->email);
    }

    private function ownersDe(Account $a): int
    {
        return $a->users()->wherePivot('role', 'owner')->count();
    }
}
