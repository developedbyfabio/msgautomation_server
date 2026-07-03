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

        // Deslogado PRIMEIRO (actingAs persiste a auth no resto do teste).
        $this->get('/admin/tenants')->assertRedirect(route('login'));      // deslogado
        $this->actingAs($comum)->get('/admin/tenants')->assertForbidden(); // 403 (logado, nao-admin)
        $this->actingAs($admin)->get('/admin/tenants')->assertOk();        // super-admin
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
}
