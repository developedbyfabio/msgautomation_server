<?php

namespace Tests\Feature;

use App\Livewire\Fluxos;
use App\Models\Account;
use App\Models\AutoReplyRule;
use App\Models\Card;
use App\Models\Contact;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\IncomingMessage;
use App\Models\Knowledge;
use App\Models\ProactiveCampaign;
use App\Models\Secret;
use App\Models\Tag;
use App\Models\User;
use App\Models\Variable;
use App\Tenancy\AccountContext;
use App\Whatsapp\Secrets\SecretVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fatia 8 — seed de demo: comando msg:seed-demo popula UMA conta explicita com
 * exemplos em todas as abas. Guardas provadas aqui: alvo obrigatorio (aborta sem
 * criar), idempotencia (2x nao duplica), isolamento (B intacta), sem disparo
 * (nenhum job/HTTP; campanha so draft), secrets FAKE, jids FAKE, fluxos da
 * Fatia 7 editaveis com handoff.
 */
class SeedDemoCommandTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'A']); // booted: board + {saudacao}
    }

    private function rodarSeed(): void
    {
        $this->artisan('msg:seed-demo', ['--account' => $this->account->id])->assertSuccessful();
    }

    /** Contagens por dominio da conta (bypass nomeado: teste compara contas). */
    private function contagens(int $accountId): array
    {
        return [
            'regras' => AutoReplyRule::withoutAccountScope()->where('account_id', $accountId)->count(),
            'secrets' => Secret::withoutAccountScope()->where('account_id', $accountId)->count(),
            'kb' => Knowledge::withoutAccountScope()->where('account_id', $accountId)->count(),
            'tags' => Tag::withoutAccountScope()->where('account_id', $accountId)->count(),
            'fluxos' => Flow::withoutAccountScope()->where('account_id', $accountId)->count(),
            'campanhas' => ProactiveCampaign::withoutAccountScope()->where('account_id', $accountId)->count(),
            'variaveis' => Variable::withoutAccountScope()->where('account_id', $accountId)->count(),
            'contatos' => Contact::withoutAccountScope()->where('account_id', $accountId)->count(),
            'mensagens' => IncomingMessage::withoutAccountScope()->where('account_id', $accountId)->count(),
            'cards' => Card::withoutAccountScope()->where('account_id', $accountId)->count(),
        ];
    }

    // ---- Alvo EXPLICITO obrigatorio ------------------------------------------------

    public function test_sem_alvo_aborta_sem_criar_nada(): void
    {
        $antes = $this->contagens($this->account->id);

        $this->artisan('msg:seed-demo')->assertFailed();

        $this->assertSame($antes, $this->contagens($this->account->id)); // nada criado
    }

    public function test_conta_inexistente_aborta_sem_criar_nada(): void
    {
        $antes = $this->contagens($this->account->id);

        $this->artisan('msg:seed-demo', ['--account' => 99999])->assertFailed();

        $this->assertSame($antes, $this->contagens($this->account->id));
    }

    public function test_email_resolve_conta_unica_e_ambiguidade_aborta(): void
    {
        $user = User::create(['name' => 'F', 'email' => 'f@ex.com', 'password' => 'secret123']);
        $user->accounts()->attach($this->account->id, ['role' => 'owner']);

        $this->artisan('msg:seed-demo', ['--email' => 'f@ex.com'])->assertSuccessful();
        $this->assertGreaterThan(0, AutoReplyRule::withoutAccountScope()->where('account_id', $this->account->id)->count());

        // Usuario com DUAS contas -> ambiguo -> aborta sem criar na segunda.
        $b = Account::create(['name' => 'B']);
        $user->accounts()->attach($b->id, ['role' => 'owner']);
        $this->artisan('msg:seed-demo', ['--email' => 'f@ex.com'])->assertFailed();
        $this->assertSame(0, AutoReplyRule::withoutAccountScope()->where('account_id', $b->id)->count());

        // Email desconhecido -> aborta.
        $this->artisan('msg:seed-demo', ['--email' => 'naoexiste@ex.com'])->assertFailed();
    }

    // ---- Popula todos os dominios ----------------------------------------------------

    public function test_popula_todos_os_dominios_na_conta_alvo(): void
    {
        $this->rodarSeed();
        $id = $this->account->id;

        // Regras: 3, variando os tipos de match (MATCH-1).
        $regras = AutoReplyRule::withoutAccountScope()->where('account_id', $id)->with('triggers')->get();
        $this->assertCount(3, $regras);
        $tipos = $regras->flatMap(fn ($r) => $r->triggers->pluck('match_type'))->unique()->sort()->values()->all();
        $this->assertSame(['contains', 'exact', 'starts_with'], $tipos);

        // Cofre: 2 entradas FAKE.
        $this->assertSame(2, Secret::withoutAccountScope()->where('account_id', $id)->count());

        // KB: 3 entradas ativas com o marcador "Exemplo — ".
        $kb = Knowledge::withoutAccountScope()->where('account_id', $id)->get();
        $this->assertCount(3, $kb);
        $this->assertTrue($kb->every(fn ($k) => str_starts_with($k->title, 'Exemplo — ') && $k->active));

        // Tags: cliente, lead, vip.
        $this->assertSame(['cliente', 'lead', 'vip'], Tag::withoutAccountScope()->where('account_id', $id)->orderBy('name')->pluck('name')->all());

        // Fluxos: os 3 templates REAIS da Fatia 7, escopados a conta.
        $nomes = Flow::withoutAccountScope()->where('account_id', $id)->orderBy('id')->pluck('name')->all();
        $this->assertSame(['Clínica / consultório', 'Salão de beleza / barbearia', 'Comércio / estabelecimento'], $nomes);

        // Campanha: 1, SO draft, sem targets.
        $campanha = ProactiveCampaign::withoutAccountScope()->where('account_id', $id)->first();
        $this->assertSame('draft', $campanha->status);
        $this->assertSame(0, $campanha->targets()->count());

        // Variaveis: {saudacao} (sistema) + {empresa} (exemplo static).
        $vars = Variable::withoutAccountScope()->where('account_id', $id)->pluck('name')->all();
        $this->assertContains('saudacao', $vars);
        $this->assertContains('empresa', $vars);

        // Contatos: 4 FAKE (DDD 00), todos saved.
        $contatos = Contact::withoutAccountScope()->where('account_id', $id)->get();
        $this->assertCount(4, $contatos);
        $this->assertTrue($contatos->every(fn ($c) => str_starts_with($c->remote_jid, '55000000000') && $c->saved));

        // Conversas: threads inertes (in + out) so pros jids FAKE, com marcador DEMO-.
        $msgs = IncomingMessage::withoutAccountScope()->where('account_id', $id)->get();
        $this->assertSame(6, $msgs->count());
        $this->assertTrue($msgs->every(fn ($m) => str_starts_with($m->evolution_message_id, 'DEMO-') && str_starts_with($m->remote_jid, '55000000000')));
        $this->assertTrue($msgs->contains(fn ($m) => (bool) $m->from_me));  // enviada (out_phone)
        $this->assertTrue($msgs->contains(fn ($m) => ! $m->from_me));       // recebida

        // Kanban: 3 cards em colunas DISTINTAS do board default.
        $cards = Card::withoutAccountScope()->where('account_id', $id)->get();
        $this->assertCount(3, $cards);
        $this->assertCount(3, $cards->pluck('column_id')->unique());
    }

    public function test_secrets_sao_placeholders_fake(): void
    {
        $this->rodarSeed();

        app(AccountContext::class)->set($this->account->id);
        $vault = app(SecretVault::class);
        $this->assertSame('TOKEN_EXEMPLO_123', $vault->reveal($this->account->id, 'token_exemplo'));
        $this->assertSame('API_KEY_DEMO_abc', $vault->reveal($this->account->id, 'api_key_demo'));
    }

    // ---- Idempotencia: 2x nao duplica ---------------------------------------------------

    public function test_rodar_duas_vezes_nao_duplica_nada(): void
    {
        $this->rodarSeed();
        $depoisDa1a = $this->contagens($this->account->id);

        $this->rodarSeed();

        $this->assertSame($depoisDa1a, $this->contagens($this->account->id));
    }

    public function test_aditivo_nao_sobrescreve_dado_existente(): void
    {
        // Segredo REAL e regra do usuario pre-existentes: o seed NAO pode tocar.
        app(AccountContext::class)->set($this->account->id);
        app(SecretVault::class)->put($this->account->id, 'token_exemplo', 'VALOR_REAL_DO_USUARIO');
        app(\App\Whatsapp\AutoReply\RuleWriter::class)->save($this->account->id, [
            'triggers' => [['type' => 'exact', 'value' => 'oi', 'precision' => 'exato']],
            'responses' => ['Resposta do usuario pra oi'],
            'enabled' => true, 'cooldown_mode' => 'global', 'scope' => 'global',
        ]);
        app(AccountContext::class)->clear();

        $this->rodarSeed();

        // Segredo preservado (nao virou o fake); regra de 'oi' nao ganhou concorrente.
        $this->assertSame('VALOR_REAL_DO_USUARIO', app(SecretVault::class)->reveal($this->account->id, 'token_exemplo'));
        $regrasOi = AutoReplyRule::withoutAccountScope()->where('account_id', $this->account->id)
            ->whereHas('triggers', fn ($q) => $q->where('match_value', 'oi'))->count();
        $this->assertSame(1, $regrasOi);
        // e as OUTRAS regras de exemplo (horario/preco) entraram normalmente.
        $this->assertSame(3, AutoReplyRule::withoutAccountScope()->where('account_id', $this->account->id)->count());
    }

    // ---- Isolamento: B intacta -----------------------------------------------------------

    public function test_semear_a_conta_a_nao_cria_nada_em_b(): void
    {
        $b = Account::create(['name' => 'B']);
        $antesB = $this->contagens($b->id);

        $this->rodarSeed();

        $this->assertSame($antesB, $this->contagens($b->id)); // so o que o provisioner de conta ja criara
        $this->assertSame(0, Flow::withoutAccountScope()->where('account_id', $b->id)->count());
        $this->assertSame(0, Contact::withoutAccountScope()->where('account_id', $b->id)->count());
    }

    // ---- Sem disparo: nenhum job, nenhum HTTP, campanha draft -----------------------------

    public function test_seed_nao_despacha_job_nem_envia_nada(): void
    {
        Queue::fake();
        Http::fake();

        $this->rodarSeed();

        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertSame('draft', ProactiveCampaign::withoutAccountScope()->where('account_id', $this->account->id)->value('status'));
    }

    // ---- Fluxos semeados: editaveis (5b) e com handoff (7) ---------------------------------

    public function test_fluxo_semeado_abre_no_editor_e_tem_handoff(): void
    {
        $this->rodarSeed();
        app(AccountContext::class)->set($this->account->id);

        $flow = Flow::withoutAccountScope()->where('account_id', $this->account->id)
            ->where('name', 'Clínica / consultório')->first();
        $handoffs = FlowNode::where('flow_id', $flow->id)->where('kind', 'handoff')->get();
        $this->assertCount(2, $handoffs); // agendar + atendente
        $this->assertTrue($handoffs->every(fn ($n) => trim((string) $n->message) !== ''));

        Livewire::test(Fluxos::class)->call('editar', $flow->id)
            ->assertSee('Agendar consulta')
            ->assertSet("nodeKind.{$handoffs->first()->id}", 'handoff');
    }
}
