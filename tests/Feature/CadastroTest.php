<?php

namespace Tests\Feature;

use App\Actions\CreateTenant;
use App\Actions\RegisterTenant;
use App\Livewire\Cadastro;
use App\Models\Account;
use App\Models\Contact;
use App\Models\User;
use App\Tenancy\AccountContext;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fatia 25 — cadastro publico PF/PJ. O coracao: provisionamento ATOMICO
 * (user + conta + pivot owner + trial, tudo ou nada), isolamento A/B do tenant
 * recem-criado, CPF/CNPJ com digito verificador real + unicidade, endereco
 * revalidado server-side, anti-abuso (rate limiting) e o MARCO de trial SEM
 * corte (o corte e da Fatia 26).
 */
class CadastroTest extends TestCase
{
    use RefreshDatabase;

    private const CPF_VALIDO = '52998224725';
    private const CNPJ_VALIDO = '11222333000181';

    /** Form PF completo e valido (com mascara: o server normaliza). */
    private function pf(array $sobrescreve = []): array
    {
        return array_merge([
            'tipo' => 'pf',
            'nome' => 'Maria da Silva',
            'documento' => '529.982.247-25',
            'email' => 'maria@exemplo.com.br',
            'telefone' => '(41) 99999-8888',
            'cep' => '80010-000',
            'endereco' => 'Rua XV de Novembro',
            'numero' => '100',
            'complemento' => 'Sala 2',
            'bairro' => 'Centro',
            'cidade' => 'Curitiba',
            'uf' => 'PR',
            'password' => 'senha-forte-123',
            'password_confirmation' => 'senha-forte-123',
            'aceite' => true,
        ], $sobrescreve);
    }

    private function cadastrar(array $dados)
    {
        $lw = Livewire::test(Cadastro::class);
        foreach ($dados as $campo => $valor) {
            $lw->set($campo, $valor);
        }

        return $lw->call('cadastrar');
    }

    // ---- pagina -------------------------------------------------------------------

    public function test_pagina_de_cadastro_renderiza_para_visitante_com_o_plano(): void
    {
        $this->get(route('cadastro'))
            ->assertOk()
            ->assertSee('Crie sua conta')
            ->assertSee(config('billing.plan.name'))
            ->assertSee(config('billing.plan.price_monthly'))
            ->assertSee('Li e aceito os Termos de Uso');
    }

    // ---- provisionamento --------------------------------------------------------

    public function test_cadastro_pf_valido_provisiona_user_conta_owner_e_trial(): void
    {
        Notification::fake();

        $this->cadastrar($this->pf())
            ->assertHasNoErrors()
            ->assertRedirect(route('verification.notice'));

        $user = User::where('email', 'maria@exemplo.com.br')->firstOrFail();
        $account = Account::where('document', self::CPF_VALIDO)->firstOrFail();

        // owner da conta (papel da Fatia 22 — primeiro owner do tenant)
        $this->assertTrue($user->isOwnerOf($account->id));
        // trial de 7 dias: status + marco de expiracao
        $this->assertSame('trial', $account->subscription_status);
        $this->assertTrue($account->trial_ends_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));
        // perfil normalizado (documento/cep/telefone so digitos)
        $this->assertSame('pf', $account->person_type);
        $this->assertSame('80010000', $account->cep);
        $this->assertSame('41999998888', $account->phone);
        $this->assertSame('Maria da Silva', $account->name);
        // NAO-verificado (self-signup e a unica origem nao-vouched) + LGPD auditavel
        $this->assertNull($user->email_verified_at);
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertSame(config('billing.terms_version'), $user->terms_version);
        // logado (vai direto pra tela "confirme seu e-mail") + e-mail disparado
        $this->assertSame($user->id, auth()->id());
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_cadastro_pj_valido_usa_fantasia_como_nome_e_guarda_razao_social(): void
    {
        Notification::fake();

        $this->cadastrar($this->pf([
            'tipo' => 'pj',
            'razaoSocial' => 'Engepecas Equipamentos LTDA',
            'nomeFantasia' => 'Engepecas',
            'nome' => 'Fabio Responsavel',
            'documento' => '11.222.333/0001-81',
            'email' => 'contato@engepecas.com.br',
        ]))->assertHasNoErrors();

        $account = Account::where('document', self::CNPJ_VALIDO)->firstOrFail();
        $this->assertSame('pj', $account->person_type);
        $this->assertSame('Engepecas', $account->name);
        $this->assertSame('Engepecas Equipamentos LTDA', $account->razao_social);
        $this->assertSame('Fabio Responsavel', $account->users()->wherePivot('role', 'owner')->first()->name);
    }

    public function test_pj_exige_razao_social(): void
    {
        $this->cadastrar($this->pf([
            'tipo' => 'pj', 'documento' => '11.222.333/0001-81', 'razaoSocial' => '',
        ]))->assertHasErrors(['razaoSocial']);
    }

    // ---- atomicidade (tudo ou nada) ---------------------------------------------

    public function test_falha_no_meio_faz_rollback_total_sem_conta_orfa(): void
    {
        // E-mail ja existente estoura a unique DENTRO da transacao (a Account e
        // criada ANTES do User no CreateTenant) — bypass da validacao de form
        // pra provar a atomicidade no nivel da ACTION.
        User::create(['name' => 'Ja Existe', 'email' => 'maria@exemplo.com.br', 'password' => bcrypt('x-123456789')]);
        $contasAntes = Account::count();
        $usersAntes = User::count();

        try {
            app(RegisterTenant::class)->handle([
                'account_name' => 'Maria da Silva', 'owner_name' => 'Maria da Silva',
                'email' => 'maria@exemplo.com.br', 'password' => 'senha-forte-123',
                'person_type' => 'pf', 'document' => self::CPF_VALIDO, 'razao_social' => null,
                'phone' => '41999998888', 'cep' => '80010000', 'endereco' => 'Rua XV',
                'numero' => '100', 'complemento' => null, 'bairro' => 'Centro',
                'cidade' => 'Curitiba', 'uf' => 'PR',
            ]);
            $this->fail('Deveria ter estourado a unique de e-mail.');
        } catch (\Illuminate\Database\QueryException) {
            // esperado
        }

        // Rollback TOTAL: nenhuma conta pela metade, nenhum user novo.
        $this->assertSame($contasAntes, Account::count());
        $this->assertSame($usersAntes, User::count());
    }

    // ---- isolamento A/B (inegociavel) -------------------------------------------

    public function test_tenant_recem_criado_nasce_isolado_nos_dois_sentidos(): void
    {
        Notification::fake();

        // Conta B pre-existente com um contato.
        $b = Account::create(['name' => 'B']);
        app(AccountContext::class)->runAs($b->id, function () use ($b) {
            Contact::create(['account_id' => $b->id, 'remote_jid' => '554199990000@s.whatsapp.net', 'push_name' => 'Cliente B']);
        });

        $this->cadastrar($this->pf())->assertHasNoErrors();
        $a = Account::where('document', self::CPF_VALIDO)->firstOrFail();

        // A nao ve nada de B; B nao ve nada de A — escopo desde o primeiro byte.
        app(AccountContext::class)->runAs($a->id, function () {
            $this->assertSame(0, Contact::count());
        });
        app(AccountContext::class)->runAs($b->id, function () {
            $this->assertSame(1, Contact::count());
            $this->assertSame('Cliente B', Contact::first()->push_name);
        });
    }

    // ---- validacao server-side ---------------------------------------------------

    public function test_cpf_com_digito_verificador_errado_e_rejeitado(): void
    {
        $this->cadastrar($this->pf(['documento' => '529.982.247-24']))
            ->assertHasErrors(['documento']);
        $this->assertSame(0, Account::whereNotNull('document')->count());
    }

    public function test_cnpj_com_digito_verificador_errado_e_rejeitado(): void
    {
        $this->cadastrar($this->pf([
            'tipo' => 'pj', 'razaoSocial' => 'X LTDA', 'documento' => '11.222.333/0001-82',
        ]))->assertHasErrors(['documento']);
    }

    public function test_documento_duplicado_e_rejeitado_uma_pessoa_uma_conta(): void
    {
        Notification::fake();
        $this->cadastrar($this->pf())->assertHasNoErrors();

        // Mesmo CPF, outro e-mail: barrado (anti-farm de trial).
        $this->cadastrar($this->pf(['email' => 'outra@exemplo.com.br']))
            ->assertHasErrors(['documento']);
        $this->assertSame(1, Account::where('document', self::CPF_VALIDO)->count());
    }

    public function test_email_duplicado_e_rejeitado(): void
    {
        User::create(['name' => 'X', 'email' => 'maria@exemplo.com.br', 'password' => bcrypt('x-123456789')]);
        $this->cadastrar($this->pf())->assertHasErrors(['email']);
    }

    public function test_endereco_e_revalidado_server_side(): void
    {
        // UF forjada e CEP curto: o ViaCEP do navegador e so conveniencia — o
        // submit NAO confia no front.
        $this->cadastrar($this->pf(['uf' => 'XX']))->assertHasErrors(['uf']);
        $this->cadastrar($this->pf(['cep' => '123']))->assertHasErrors(['cep']);
        $this->cadastrar($this->pf(['endereco' => '']))->assertHasErrors(['endereco']);
    }

    public function test_preencher_endereco_ignora_uf_invalida_e_corta_tamanho(): void
    {
        Livewire::test(Cadastro::class)
            ->call('preencherEndereco', ['logradouro' => str_repeat('a', 500), 'uf' => 'ZZ', 'localidade' => 'Curitiba'])
            ->assertSet('uf', '')
            ->assertSet('cidade', 'Curitiba');
    }

    public function test_aceite_dos_termos_e_obrigatorio(): void
    {
        $this->cadastrar($this->pf(['aceite' => false]))->assertHasErrors(['aceite']);
    }

    // ---- anti-abuso ---------------------------------------------------------------

    public function test_rate_limit_de_submissoes_por_ip(): void
    {
        foreach (range(1, 15) as $i) {
            RateLimiter::hit('cadastro-ip:127.0.0.1', 600);
        }

        $this->cadastrar($this->pf())->assertHasErrors(['email']);
        $this->assertSame(0, Account::whereNotNull('document')->count());
    }

    public function test_rate_limit_de_criacoes_por_ip(): void
    {
        foreach (range(1, 3) as $i) {
            RateLimiter::hit('cadastro-criadas-ip:127.0.0.1', 3600);
        }

        $this->cadastrar($this->pf())->assertHasErrors(['email']);
        $this->assertSame(0, Account::whereNotNull('document')->count());
    }

    // ---- fronteira do trial (Fatia 26) --------------------------------------------

    public function test_trial_vencido_nao_bloqueia_nada_o_corte_e_da_fatia_26(): void
    {
        Notification::fake();
        $this->cadastrar($this->pf())->assertHasNoErrors();

        $user = User::where('email', 'maria@exemplo.com.br')->firstOrFail();
        $user->markEmailAsVerified();

        $this->travel(10)->days(); // trial (7d) VENCIDO

        // Painel segue 200: esta fatia grava SO o marco; o corte e do billing.
        $this->actingAs($user->fresh())->get(route('perfil'))->assertOk();
    }

    // ---- contas legadas -----------------------------------------------------------

    public function test_contas_criadas_pelo_admin_seguem_active_sem_trial(): void
    {
        ['account' => $account] = app(CreateTenant::class)
            ->handle('Legada', 'Dono', 'dono@legada.com.br', 'senha-forte-123');

        $this->assertSame('active', $account->fresh()->subscription_status);
        $this->assertNull($account->fresh()->trial_ends_at);
        $this->assertNull($account->fresh()->document);
    }
}
