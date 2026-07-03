<?php

namespace Tests\Feature;

use App\Livewire\Login;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 28 — endurecimento do login: rate limit (conta+IP e IP), anti-enumeracao
 * (mensagem generica), sem regredir login/2FA.
 */
class LoginHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email = 'op@exemplo.local', string $senha = 'segredo-forte-123'): User
    {
        Account::firstOrCreate(['name' => 'T']);

        return User::create(['name' => 'Op', 'email' => $email, 'password' => Hash::make($senha)]);
    }

    public function test_login_valido_funciona_nao_regride(): void
    {
        $this->user();

        Livewire::test(Login::class)
            ->set('email', 'op@exemplo.local')->set('password', 'segredo-forte-123')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('conversas'));

        $this->assertAuthenticated();
    }

    public function test_muitas_tentativas_erradas_bloqueia_com_mensagem_clara(): void
    {
        $this->user();

        // 5 erradas (consome o freio por conta+IP)
        for ($i = 0; $i < 5; $i++) {
            Livewire::test(Login::class)
                ->set('email', 'op@exemplo.local')->set('password', 'errada')
                ->call('login')->assertHasErrors('email');
        }

        // 6a: bloqueada
        Livewire::test(Login::class)
            ->set('email', 'op@exemplo.local')->set('password', 'errada')
            ->call('login')
            ->assertHasErrors('email')
            ->assertSee('Muitas tentativas');
    }

    public function test_anti_enumeracao_mesma_mensagem_email_inexistente_e_senha_errada(): void
    {
        $this->user(); // op@exemplo.local existe

        // email inexistente
        Livewire::test(Login::class)
            ->set('email', 'naoexiste@x.local')->set('password', 'qualquer-coisa')
            ->call('login')
            ->assertHasErrors('email')
            ->assertSee('Credenciais invalidas.')
            ->assertDontSee('Muitas tentativas');

        // email existente + senha errada -> MESMA mensagem
        Livewire::test(Login::class)
            ->set('email', 'op@exemplo.local')->set('password', 'senha-errada')
            ->call('login')
            ->assertHasErrors('email')
            ->assertSee('Credenciais invalidas.');
    }

    public function test_freio_por_ip_corta_password_spraying(): void
    {
        $this->user();
        // Simula 20 tentativas de emails distintos do MESMO IP (127.0.0.1 nos testes).
        for ($i = 0; $i < 20; $i++) {
            RateLimiter::hit('login-ip:127.0.0.1', 60);
        }

        // Proxima tentativa (mesmo com credenciais validas) e barrada pelo freio de IP.
        Livewire::test(Login::class)
            ->set('email', 'op@exemplo.local')->set('password', 'segredo-forte-123')
            ->call('login')
            ->assertHasErrors('email')
            ->assertSee('Muitas tentativas');

        $this->assertGuest(); // nao logou apesar da senha certa (spray guard ativo)
    }
}
