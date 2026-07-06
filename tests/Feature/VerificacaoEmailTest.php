<?php

namespace Tests\Feature;

use App\Actions\CreateTenant;
use App\Models\Account;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Fatia 25 — verificacao de e-mail (MustVerifyEmail nativo + Fortify
 * emailVerification). O painel operacional fica ATRAS do middleware 'verified':
 * quem veio do cadastro publico so entra depois de clicar no link assinado.
 * Usuarios criados por caminho PRIVILEGIADO (console/admin/CreateTenant) nascem
 * verificados por construcao — e por isso a suite legada inteira segue verde.
 * Roda com MAIL_MAILER=array (nao exige SMTP; transporte real e .env do Fabio).
 */
class VerificacaoEmailTest extends TestCase
{
    use RefreshDatabase;

    /** Usuario de CADASTRO (nao-verificado, owner de uma conta em trial). */
    private function usuarioDeCadastro(): User
    {
        $account = Account::create(['name' => 'Nova']);
        $user = User::create([
            'name' => 'Recem Cadastrado', 'email' => 'novo@exemplo.com.br',
            'password' => bcrypt('senha-forte-123'),
        ]);
        // Igual ao RegisterTenant: email_verified_at NAO e fillable (passar no
        // create() e descartado pelo guard e o default 'verificado' entra) —
        // marcar nao-verificado exige forceFill explicito. Friccao proposital.
        $user->forceFill(['email_verified_at' => null])->save();
        $user->accounts()->attach($account->id, ['role' => 'owner']);

        return $user;
    }

    public function test_nao_verificado_nao_entra_no_painel_e_ve_o_aviso(): void
    {
        $user = $this->usuarioDeCadastro();

        // Painel operacional barrado (gate 'verified' no grupo de rotas)...
        $this->actingAs($user)->get(route('perfil'))->assertRedirect(route('verification.notice'));
        $this->actingAs($user)->get(route('conexao'))->assertRedirect(route('verification.notice'));

        // ...e a UNICA tela liberada e o aviso, com reenvio e saida.
        $this->actingAs($user)->get(route('verification.notice'))
            ->assertOk()
            ->assertSee('Confirme seu e-mail')
            ->assertSee('novo@exemplo.com.br')
            ->assertSee('Reenviar e-mail');
    }

    public function test_reenvio_dispara_a_notificacao(): void
    {
        Notification::fake();
        $user = $this->usuarioDeCadastro();

        $this->actingAs($user)
            ->from(route('verification.notice'))
            ->post(route('verification.send'))
            ->assertRedirect(route('verification.notice'));

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_link_assinado_verifica_e_libera_o_painel(): void
    {
        $user = $this->usuarioDeCadastro();

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id, 'hash' => sha1($user->email),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect('/conversas?verified=1');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());

        // Painel liberado (perfil nao depende do gate de conexao).
        $this->actingAs($user->fresh())->get(route('perfil'))->assertOk();
    }

    public function test_link_forjado_nao_verifica(): void
    {
        $user = $this->usuarioDeCadastro();

        // hash de OUTRO e-mail quebra a assinatura -> 403; segue nao-verificado.
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id, 'hash' => sha1('atacante@exemplo.com.br'),
        ]);

        $this->actingAs($user)->get($url)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_caminhos_privilegiados_nascem_verificados_por_construcao(): void
    {
        // CreateTenant (admin) e User::create direto (console/suite legada):
        // quem cria responde pelo e-mail — painel acessivel na hora, sem link.
        ['owner' => $owner, 'account' => $account] = app(CreateTenant::class)
            ->handle('Empresa Admin', 'Dono', 'dono@admin.com.br', 'senha-forte-123');

        $this->assertTrue($owner->fresh()->hasVerifiedEmail());
        $this->actingAs($owner)->get(route('perfil'))->assertOk();

        $direto = User::create(['name' => 'Legado', 'email' => 'legado@x.com.br', 'password' => bcrypt('x-123456789')]);
        $this->assertTrue($direto->fresh()->hasVerifiedEmail());
    }
}
