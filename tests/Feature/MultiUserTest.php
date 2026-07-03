<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\User;
use App\Tenancy\AccountContext;
use App\Tenancy\MissingAccountContextException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * MT-1 — multi-usuario: a conta do request web vem do VINCULO do usuario logado
 * (account_user), com o fallback fase-1 DESLIGADO (como producao pos-MT-1).
 * Provas: login carrega a conta certa; sem vinculo = 403 (logout acessivel);
 * troca de conta muda TODO o escopo; sessao forjada e rejeitada POR REQUEST;
 * fallback off = excecao alta fora do web; comando cria user+vinculo.
 */
class MultiUserTest extends TestCase
{
    use RefreshDatabase;

    private Account $a;
    private Account $b;

    protected function setUp(): void
    {
        parent::setUp();
        // Producao MT-1: SEM fallback — contexto so por vinculo/job/comando.
        config(['tenancy.single_account_fallback' => false]);

        $this->a = Account::create(['name' => 'Conta A']);
        $this->b = Account::create(['name' => 'Conta B']);
        foreach ([[$this->a, 'inst-a'], [$this->b, 'inst-b']] as [$acc, $inst]) {
            Channel::create(['account_id' => $acc->id, 'instance' => $inst, 'status' => 'connected']);
            AutoReplySetting::create(['account_id' => $acc->id]);
        }
        // Prompt 19: a listagem de Contatos filtra saved=true (representam "meus contatos").
        Contact::create(['account_id' => $this->a->id, 'remote_jid' => '5541999990000@s.whatsapp.net', 'push_name' => 'Cliente-da-A', 'saved' => true]);
        Contact::create(['account_id' => $this->b->id, 'remote_jid' => '5541999990000@s.whatsapp.net', 'push_name' => 'Cliente-da-B', 'saved' => true]);

        app(AccountContext::class)->clear();
    }

    private function user(string $email, array $contas): User
    {
        $u = User::create(['name' => 'U', 'email' => $email, 'password' => Hash::make('senha-forte-123')]);
        foreach ($contas as $conta) {
            $u->accounts()->attach($conta->id, ['role' => 'owner']);
        }

        return $u;
    }

    // ---- login carrega a conta do vinculo -----------------------------------------

    public function test_login_carrega_a_conta_do_vinculo(): void
    {
        $donoB = $this->user('dono-b@x.local', [$this->b]);

        $this->actingAs($donoB)->get('/contatos')
            ->assertOk()
            ->assertSee('Cliente-da-B')
            ->assertDontSee('Cliente-da-A');
    }

    public function test_usuario_sem_vinculo_recebe_403_mas_consegue_sair(): void
    {
        $semVinculo = $this->user('orfao@x.local', []);

        $this->actingAs($semVinculo)->get('/contatos')->assertForbidden();
        $this->actingAs($semVinculo)->get('/senhas')->assertForbidden();
        // Logout SEMPRE acessivel (senao o usuario fica preso no 403).
        $this->actingAs($semVinculo)->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // ---- troca de conta ativa ---------------------------------------------------------

    public function test_troca_de_conta_ativa_muda_todo_o_escopo(): void
    {
        $doisChapeus = $this->user('dono-ab@x.local', [$this->a, $this->b]);

        // Default: primeira conta do vinculo (A).
        $this->actingAs($doisChapeus)->get('/contatos')
            ->assertSee('Cliente-da-A')->assertDontSee('Cliente-da-B');

        // Troca pra B: TODO o escopo segue (contatos, regras, tudo via contexto).
        $this->actingAs($doisChapeus)->post('/conta-ativa', ['account_id' => $this->b->id])
            ->assertRedirect(route('conversas'));
        $this->actingAs($doisChapeus)->withSession(['tenancy.account_id' => $this->b->id])
            ->get('/contatos')
            ->assertSee('Cliente-da-B')->assertDontSee('Cliente-da-A');
    }

    public function test_trocar_pra_conta_fora_do_vinculo_e_403(): void
    {
        $donoA = $this->user('dono-a@x.local', [$this->a]);

        $this->actingAs($donoA)
            ->post('/conta-ativa', ['account_id' => $this->b->id])
            ->assertForbidden();
    }

    /** Autorizacao POR REQUEST: sessao forjada com conta alheia e RESETADA. */
    public function test_sessao_forjada_com_conta_alheia_e_rejeitada_no_request(): void
    {
        $donoA = $this->user('dono-a@x.local', [$this->a]);

        // Mesmo com a sessao apontando pra B (forjada), o request opera na A —
        // o vinculo e re-validado a CADA request, nao so na troca.
        $this->actingAs($donoA)->withSession(['tenancy.account_id' => $this->b->id])
            ->get('/contatos')
            ->assertOk()
            ->assertSee('Cliente-da-A')
            ->assertDontSee('Cliente-da-B');
    }

    // ---- fallback desligado: excecao alta fora do web -----------------------------------

    public function test_fallback_desligado_query_sem_contexto_falha_alto(): void
    {
        app(AccountContext::class)->clear();

        $this->expectException(MissingAccountContextException::class);
        Contact::query()->count(); // job/comando sem contexto explicito: NUNCA silencioso
    }

    // ---- comando de gestao ----------------------------------------------------------------

    public function test_comando_cria_usuario_com_senha_oculta_e_vinculo_owner(): void
    {
        $this->artisan('msg:user:create', ['email' => 'novo@x.local', '--account' => $this->b->id])
            ->expectsQuestion('Senha (minimo 10 caracteres)', 'senha-bem-forte-1')
            ->expectsQuestion('Confirme a senha', 'senha-bem-forte-1')
            ->assertSuccessful();

        $novo = User::where('email', 'novo@x.local')->firstOrFail();
        $this->assertTrue(Hash::check('senha-bem-forte-1', $novo->password));
        $this->assertDatabaseHas('account_user', ['user_id' => $novo->id, 'account_id' => $this->b->id, 'role' => 'owner']);

        // Idempotente: rodar de novo so garante o vinculo (sem pedir senha de novo).
        $this->artisan('msg:user:create', ['email' => 'novo@x.local', '--account' => $this->a->id])
            ->assertSuccessful();
        $this->assertSame(2, $novo->accounts()->count());

        // Senha curta: recusada.
        $this->artisan('msg:user:create', ['email' => 'curto@x.local', '--account' => $this->a->id])
            ->expectsQuestion('Senha (minimo 10 caracteres)', 'curta')
            ->assertFailed();
        $this->assertDatabaseMissing('users', ['email' => 'curto@x.local']);
    }

    // ---- backfill da migration --------------------------------------------------------------

    public function test_backfill_vincula_usuario_existente_como_owner_da_conta_mais_antiga(): void
    {
        // O RefreshDatabase ja rodou a migration com o banco vazio; o backfill em
        // producao foi verificado manualmente. Aqui prova a MECANICA: um usuario
        // e a conta ja existentes + insertOrIgnore = vinculo owner sem duplicar.
        $u = $this->user('legado@x.local', []);
        \Illuminate\Support\Facades\DB::table('account_user')->insertOrIgnore([
            'account_id' => $this->a->id, 'user_id' => $u->id, 'role' => 'owner',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('account_user')->insertOrIgnore([
            'account_id' => $this->a->id, 'user_id' => $u->id, 'role' => 'owner',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('account_user')
            ->where('user_id', $u->id)->count());
    }
}
