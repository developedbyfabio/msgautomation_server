<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Prompt 29 — 2FA OBRIGATORIO pro super-admin acessar /admin/*. Tenant comum NAO e
 * afetado (2FA segue opt-in fora de /admin/*). Sem loop (destino /perfil).
 */
class AdminDoisFatoresTest extends TestCase
{
    use RefreshDatabase;

    private function user(Account $conta, bool $admin, bool $com2fa, string $email): User
    {
        $u = User::create(['name' => 'U', 'email' => $email, 'password' => Hash::make('senha-forte-123')]);
        $u->accounts()->attach($conta->id, ['role' => 'owner']);
        if ($admin) {
            $u->forceFill(['is_platform_admin' => true])->save();
        }
        if ($com2fa) {
            $u->forceFill(['two_factor_secret' => encrypt('SEGREDO2FA'), 'two_factor_confirmed_at' => now()])->save();
        }

        return $u;
    }

    public function test_super_admin_sem_2fa_e_mandado_pro_perfil(): void
    {
        $c = Account::create(['name' => 'Base']);
        $admin = $this->user($c, admin: true, com2fa: false, email: 'admin@plat.local');

        $this->actingAs($admin)->get('/admin/tenants')
            ->assertRedirect(route('perfil')); // levado a ativar o 2FA

        // aviso flashado pra sessao
        $this->assertNotNull(session('aviso'));
    }

    public function test_super_admin_com_2fa_acessa_admin(): void
    {
        $c = Account::create(['name' => 'Base']);
        $admin = $this->user($c, admin: true, com2fa: true, email: 'admin@plat.local');

        $this->actingAs($admin)->get('/admin/tenants')->assertOk();
    }

    public function test_usuario_comum_recebe_403_inalterado(): void
    {
        $c = Account::create(['name' => 'Base']);
        $comum = $this->user($c, admin: false, com2fa: false, email: 'comum@cli.local');

        $this->actingAs($comum)->get('/admin/tenants')->assertForbidden(); // 403 (platform.admin)
    }

    public function test_tenant_comum_sem_2fa_acessa_o_painel_normal(): void
    {
        // A obrigatoriedade de 2FA NAO vaza pra fora de /admin/*.
        $c = Account::create(['name' => 'Tenant Comum']);
        $comum = $this->user($c, admin: false, com2fa: false, email: 'user@cli.local');

        $this->actingAs($comum)->get('/perfil')->assertOk(); // painel normal, sem 2FA
    }

    public function test_sem_loop_perfil_acessivel_pro_super_admin_sem_2fa(): void
    {
        $c = Account::create(['name' => 'Base']);
        $admin = $this->user($c, admin: true, com2fa: false, email: 'admin@plat.local');

        // /perfil (destino do redirect) e fora de /admin/* -> acessivel, sem loop.
        $this->actingAs($admin)->get('/perfil')->assertOk();
    }
}
