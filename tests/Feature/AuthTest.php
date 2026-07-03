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
        // MT-0: as telas operam na conta do CONTEXTO — como em producao, a conta
        // existe antes da UI (seeder). O teste de auth reflete esse estado.
        $account = \App\Models\Account::firstOrCreate(['name' => 'T']);
        // Prompt 27 (Fatia 2): conta ONBOARDED (com canal conectado) — o gate
        // whatsapp.connected agora manda conta SEM canal pra /conexao.
        \App\Models\Channel::withoutAccountScope()->firstOrCreate(
            ['account_id' => $account->id],
            ['instance' => 'conta-' . $account->id . '-t', 'provider' => 'evolution', 'webhook_token' => 'tok-t', 'status' => 'connected'],
        );

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

    public function test_seeder_garante_usuario_sem_senha_em_texto(): void
    {
        config()->set('auth.single_user', ['name' => 'Operador', 'email' => 'unico@exemplo.local']);

        $this->seed(\Database\Seeders\SingleUserSeeder::class);

        // Usuario existe, mas a senha e um hash aleatorio (conta trancada ate o comando).
        $this->assertDatabaseHas('users', ['email' => 'unico@exemplo.local']);
        $this->assertFalse(\Illuminate\Support\Facades\Auth::attempt([
            'email' => 'unico@exemplo.local', 'password' => '',
        ]));
    }

    public function test_seeder_nao_clobbera_senha_existente(): void
    {
        $user = $this->user('senha-do-fabio-1');

        $this->seed(\Database\Seeders\SingleUserSeeder::class);

        // Seeder roda mas NAO troca a senha ja definida.
        $this->assertTrue(Hash::check('senha-do-fabio-1', $user->fresh()->password));
    }

    // ---- C1: comando msg:auth:senha (input oculto, hash no banco) -----------

    public function test_comando_define_senha_e_loga(): void
    {
        config()->set('auth.single_user.email', 'op@exemplo.local');

        $this->artisan('msg:auth:senha')
            ->expectsQuestion('Nova senha (nao aparece na tela)', 'senhaforte9')
            ->expectsQuestion('Confirme a senha', 'senhaforte9')
            ->assertExitCode(0);

        $this->assertTrue(\Illuminate\Support\Facades\Auth::attempt([
            'email' => 'op@exemplo.local', 'password' => 'senhaforte9',
        ]));
    }

    public function test_comando_senha_curta_falha(): void
    {
        $this->artisan('msg:auth:senha')
            ->expectsQuestion('Nova senha (nao aparece na tela)', 'curta')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_comando_confirmacao_diferente_falha(): void
    {
        $this->artisan('msg:auth:senha')
            ->expectsQuestion('Nova senha (nao aparece na tela)', 'senhaforte9')
            ->expectsQuestion('Confirme a senha', 'outracoisa9')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_comando_troca_email(): void
    {
        $this->user('qualquer-1');

        $this->artisan('msg:auth:senha --email=novo@exemplo.local')
            ->expectsQuestion('Nova senha (nao aparece na tela)', 'senhaforte9')
            ->expectsQuestion('Confirme a senha', 'senhaforte9')
            ->assertExitCode(0);

        $this->assertTrue(\Illuminate\Support\Facades\Auth::attempt([
            'email' => 'novo@exemplo.local', 'password' => 'senhaforte9',
        ]));
    }
}
