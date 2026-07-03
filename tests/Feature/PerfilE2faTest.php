<?php

namespace Tests\Feature;

use App\Livewire\Login;
use App\Livewire\Perfil;
use App\Models\Account;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Prompt 01 — 2FA (TOTP via Fortify) + pagina /perfil. O login Livewire/Flux
 * continua o mesmo; com 2FA CONFIRMADO ele nao autentica direto: cai no
 * /two-factor-challenge (POST do Fortify, throttle proprio). Perfil so mexe no
 * PROPRIO usuario (MT-1).
 */
class PerfilE2faTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'senha-forte-123';

    private Account $account;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.single_account_fallback' => false]);

        $this->account = Account::create(['name' => 'Conta A']);
        Channel::create(['account_id' => $this->account->id, 'instance' => 'inst-a', 'status' => 'connected']);
        AutoReplySetting::create(['account_id' => $this->account->id]);

        $this->user = User::create(['name' => 'Fabio', 'email' => 'dono@teste.local', 'password' => Hash::make(self::SENHA)]);
        $this->user->accounts()->attach($this->account->id, ['role' => 'owner']);
    }

    private function ligar2fa(User $user): string
    {
        app(EnableTwoFactorAuthentication::class)($user);
        $secret = decrypt($user->fresh()->two_factor_secret);

        // Confirma com o codigo do SLICE ANTERIOR (valido na janela de tolerancia):
        // o Fortify tem guarda anti-replay — se confirmar com o codigo ATUAL, o
        // mesmo codigo nao vale de novo no challenge do proprio teste.
        $g = new Google2FA;
        $codigoAnterior = $g->oathTotp($secret, $g->getTimestamp() - 1);
        app(ConfirmTwoFactorAuthentication::class)($user->fresh(), $codigoAnterior);

        return $secret;
    }

    // ---- ativacao pelo /perfil ------------------------------------------------------

    public function test_ativar_2fa_exige_senha_gera_qr_e_confirmar_com_codigo_liga(): void
    {
        // Senha errada: nada acontece.
        Livewire::actingAs($this->user)->test(Perfil::class)
            ->set('senha2fa', 'errada')
            ->call('ativar2fa')
            ->assertHasErrors('senha2fa');
        $this->assertNull($this->user->fresh()->two_factor_secret);

        // Senha certa: secret gerado (pendente), QR renderizavel.
        $c = Livewire::actingAs($this->user)->test(Perfil::class)
            ->set('senha2fa', self::SENHA)
            ->call('ativar2fa')
            ->assertHasNoErrors();
        $user = $this->user->fresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertStringContainsString('<svg', $user->twoFactorQrCodeSvg());

        // Codigo invalido nao liga; valido LIGA e mostra recovery codes.
        $c->set('codigo2fa', '000000')->call('confirmar2fa')->assertHasErrors('codigo2fa');
        $this->assertNull($this->user->fresh()->two_factor_confirmed_at);

        $otp = (new Google2FA)->getCurrentOtp(decrypt($user->two_factor_secret));
        $c->set('codigo2fa', $otp)->call('confirmar2fa')->assertHasNoErrors();
        $user = $this->user->fresh();
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertCount(8, $user->recoveryCodes());
    }

    // ---- login com desafio -----------------------------------------------------------

    public function test_login_com_2fa_ativo_cai_no_challenge_e_codigo_valido_autentica(): void
    {
        $secret = $this->ligar2fa($this->user);

        // Credenciais validas NAO autenticam direto: desafio pendente.
        Livewire::test(Login::class)
            ->set('email', $this->user->email)
            ->set('password', self::SENHA)
            ->call('login')
            ->assertRedirect(route('two-factor.login'));
        $this->assertGuest();

        // Tela do desafio renderiza com o desafio na sessao; sem sessao volta pro login.
        $this->withSession(['login.id' => $this->user->id, 'login.remember' => false])
            ->get('/two-factor-challenge')->assertOk()->assertSee('duas etapas');
        $this->flushSession();
        $this->get('/two-factor-challenge')->assertRedirect(route('login'));

        // Codigo INVALIDO barra (segue guest); codigo valido autentica.
        $this->withSession(['login.id' => $this->user->id, 'login.remember' => false])
            ->post('/two-factor-challenge', ['code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->withSession(['login.id' => $this->user->id, 'login.remember' => false])
            ->post('/two-factor-challenge', ['code' => (new Google2FA)->getCurrentOtp($secret)])
            ->assertRedirect('/conversas');
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_recovery_code_autentica_e_e_rotacionado(): void
    {
        $this->ligar2fa($this->user);
        $codes = $this->user->fresh()->recoveryCodes();
        $usado = $codes[0];

        $this->withSession(['login.id' => $this->user->id, 'login.remember' => false])
            ->post('/two-factor-challenge', ['recovery_code' => $usado])
            ->assertRedirect('/conversas');
        $this->assertAuthenticatedAs($this->user);

        // O codigo usado morreu (substituido por um novo).
        $this->assertNotContains($usado, $this->user->fresh()->recoveryCodes());
    }

    public function test_challenge_tem_rate_limit(): void
    {
        $this->ligar2fa($this->user);

        for ($i = 0; $i < 5; $i++) {
            $this->withSession(['login.id' => $this->user->id, 'login.remember' => false])
                ->post('/two-factor-challenge', ['code' => '000000']);
        }
        $this->withSession(['login.id' => $this->user->id, 'login.remember' => false])
            ->post('/two-factor-challenge', ['code' => '000000'])
            ->assertStatus(429);
    }

    public function test_login_sem_2fa_continua_direto_como_sempre(): void
    {
        Livewire::test(Login::class)
            ->set('email', $this->user->email)
            ->set('password', self::SENHA)
            ->call('login')
            ->assertRedirect(route('conversas'));
        $this->assertAuthenticatedAs($this->user);
    }

    // ---- desativar / regenerar ---------------------------------------------------------

    public function test_desativar_2fa_exige_senha(): void
    {
        $this->ligar2fa($this->user);

        Livewire::actingAs($this->user)->test(Perfil::class)
            ->set('senha2fa', 'errada')->call('desativar2fa')->assertHasErrors('senha2fa');
        $this->assertNotNull($this->user->fresh()->two_factor_confirmed_at);

        Livewire::actingAs($this->user)->test(Perfil::class)
            ->set('senha2fa', self::SENHA)->call('desativar2fa')->assertHasNoErrors();
        $u = $this->user->fresh();
        $this->assertNull($u->two_factor_secret);
        $this->assertNull($u->two_factor_confirmed_at);
    }

    public function test_regenerar_recovery_codes_exige_senha_e_troca_os_codigos(): void
    {
        $this->ligar2fa($this->user);
        $antes = $this->user->fresh()->recoveryCodes();

        Livewire::actingAs($this->user)->test(Perfil::class)
            ->set('senha2fa', 'errada')->call('regenerarCodigos')->assertHasErrors('senha2fa');
        $this->assertSame($antes, $this->user->fresh()->recoveryCodes());

        Livewire::actingAs($this->user)->test(Perfil::class)
            ->set('senha2fa', self::SENHA)->call('regenerarCodigos')->assertHasNoErrors();
        $this->assertNotSame($antes, $this->user->fresh()->recoveryCodes());
    }

    // ---- email / senha --------------------------------------------------------------------

    public function test_trocar_email_exige_senha_e_valida_formato_e_unicidade(): void
    {
        $outro = User::create(['name' => 'Outro', 'email' => 'outro@teste.local', 'password' => Hash::make('x-1234567890')]);

        // Senha errada barra.
        Livewire::actingAs($this->user)->test(Perfil::class)
            ->set('emailNovo', 'novo@teste.local')->set('senhaEmail', 'errada')
            ->call('salvarEmail')->assertHasErrors('senhaEmail');
        $this->assertSame('dono@teste.local', $this->user->fresh()->email);

        // Invalido e duplicado barram.
        Livewire::actingAs($this->user)->test(Perfil::class)
            ->set('emailNovo', 'nao-e-email')->set('senhaEmail', self::SENHA)
            ->call('salvarEmail')->assertHasErrors('emailNovo');
        Livewire::actingAs($this->user)->test(Perfil::class)
            ->set('emailNovo', $outro->email)->set('senhaEmail', self::SENHA)
            ->call('salvarEmail')->assertHasErrors('emailNovo');

        // Valido persiste.
        Livewire::actingAs($this->user)->test(Perfil::class)
            ->set('emailNovo', 'novo@teste.local')->set('senhaEmail', self::SENHA)
            ->call('salvarEmail')->assertHasNoErrors();
        $this->assertSame('novo@teste.local', $this->user->fresh()->email);
    }

    public function test_trocar_senha_exige_atual_e_forca_minima_e_permite_login_com_a_nova(): void
    {
        // Atual errada barra.
        Livewire::actingAs($this->user)->test(Perfil::class)
            ->set('senhaAtual', 'errada')->set('senhaNova', 'NovaSenha123')->set('senhaNova_confirmation', 'NovaSenha123')
            ->call('salvarSenha')->assertHasErrors('senhaAtual');

        // Fraca barra (sem numeros).
        Livewire::actingAs($this->user)->test(Perfil::class)
            ->set('senhaAtual', self::SENHA)->set('senhaNova', 'abcdefgh')->set('senhaNova_confirmation', 'abcdefgh')
            ->call('salvarSenha')->assertHasErrors('senhaNova');

        // Valida troca e permite login com a nova.
        Livewire::actingAs($this->user)->test(Perfil::class)
            ->set('senhaAtual', self::SENHA)->set('senhaNova', 'NovaSenha123')->set('senhaNova_confirmation', 'NovaSenha123')
            ->call('salvarSenha')->assertHasNoErrors();
        $this->assertTrue(Hash::check('NovaSenha123', $this->user->fresh()->password));

        auth()->logout();
        Livewire::test(Login::class)
            ->set('email', $this->user->email)->set('password', 'NovaSenha123')
            ->call('login')->assertRedirect(route('conversas'));
    }

    // ---- isolamento (MT-1) ------------------------------------------------------------------

    public function test_perfil_so_mostra_e_edita_o_proprio_usuario(): void
    {
        $b = User::create(['name' => 'Dono B', 'email' => 'b@teste.local', 'password' => Hash::make('senha-do-b-123')]);
        $contaB = Account::create(['name' => 'Conta B']);
        Channel::withoutAccountScope()->create(['account_id' => $contaB->id, 'instance' => 'inst-b', 'status' => 'connected']);
        AutoReplySetting::withoutAccountScope()->create(['account_id' => $contaB->id]);
        $b->accounts()->attach($contaB->id, ['role' => 'owner']);

        // B ve os dados DELE (nao os do A) e a troca so muda a linha dele.
        Livewire::actingAs($b)->test(Perfil::class)
            ->assertSee('b@teste.local')->assertDontSee('dono@teste.local')
            ->set('emailNovo', 'b-novo@teste.local')->set('senhaEmail', 'senha-do-b-123')
            ->call('salvarEmail')->assertHasNoErrors();

        $this->assertSame('b-novo@teste.local', $b->fresh()->email);
        $this->assertSame('dono@teste.local', $this->user->fresh()->email); // A intacto
    }
}
