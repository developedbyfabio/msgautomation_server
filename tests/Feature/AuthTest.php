<?php

namespace Tests\Feature;

use App\Livewire\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * S2 — login single-user. A UI estava aberta na LAN sem auth.
 * Toda rota da UI exige sessao autenticada.
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $password = 'segredo-forte-123'): User
    {
        return User::create([
            'name' => 'Operador',
            'email' => 'op@exemplo.local',
            'password' => Hash::make($password),
        ]);
    }

    public function test_rota_da_ui_bloqueada_sem_login(): void
    {
        foreach (['/conversas', '/contatos', '/regras', '/configuracoes'] as $rota) {
            $this->get($rota)->assertRedirect(route('login'));
        }
    }

    public function test_rota_acessivel_apos_login(): void
    {
        $this->actingAs($this->user());

        $this->get('/conversas')->assertOk();
        $this->get('/contatos')->assertOk();
    }

    public function test_login_valido_autentica_e_redireciona(): void
    {
        $this->user('senha-correta-1');

        Livewire::test(Login::class)
            ->set('email', 'op@exemplo.local')
            ->set('password', 'senha-correta-1')
            ->call('login')
            ->assertRedirect(route('conversas'));

        $this->assertAuthenticated();
    }

    public function test_login_invalido_falha(): void
    {
        $this->user('senha-correta-1');

        Livewire::test(Login::class)
            ->set('email', 'op@exemplo.local')
            ->set('password', 'errada')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_logout_encerra_sessao(): void
    {
        $this->actingAs($this->user());

        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_seeder_cria_usuario_unico_do_env(): void
    {
        config()->set('auth.single_user', [
            'name' => 'Operador',
            'email' => 'unico@exemplo.local',
            'password' => 'abc123def456',
        ]);

        $this->seed(\Database\Seeders\SingleUserSeeder::class);

        $this->assertDatabaseHas('users', ['email' => 'unico@exemplo.local']);
        $user = User::where('email', 'unico@exemplo.local')->first();
        $this->assertTrue(Hash::check('abc123def456', $user->password));
    }
}
