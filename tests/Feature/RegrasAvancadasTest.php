<?php

namespace Tests\Feature;

use App\Livewire\Regras;
use App\Models\Account;
use App\Models\AutoReplyRule;
use App\Whatsapp\AutoReply\RuleMatcher;
use App\Whatsapp\AutoReply\RuleResponder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * S7 — regras avancadas: multiplos gatilhos, respostas aleatorias, placeholders,
 * regex (valido/invalido) e CRUD via UI. Sem envio real.
 */
class RegrasAvancadasTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;
    private RuleMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'Teste']);
        $this->matcher = app(RuleMatcher::class);
    }

    private function ruleComGatilhos(array $triggers, array $responses = ['ok'], int $priority = 0): AutoReplyRule
    {
        $rule = AutoReplyRule::create([
            'account_id' => $this->account->id,
            'match_type' => $triggers[0]['match_type'],
            'match_value' => $triggers[0]['match_value'],
            'response_text' => $responses[0],
            'enabled' => true,
            'priority' => $priority,
        ]);
        $rule->triggers()->createMany($triggers);
        $rule->responses()->createMany(array_map(fn ($r) => ['response_text' => $r], $responses));

        return $rule;
    }

    private function match(?string $text): ?AutoReplyRule
    {
        return $this->matcher->match($this->account->id, null, $text);
    }

    // ---- Multiplos gatilhos -------------------------------------------------

    public function test_qualquer_gatilho_casa(): void
    {
        $rule = $this->ruleComGatilhos([
            ['match_type' => 'contains', 'match_value' => 'horario'],
            ['match_type' => 'contains', 'match_value' => 'funciona'],
            ['match_type' => 'starts_with', 'match_value' => 'bom dia'],
        ]);

        $this->assertSame($rule->id, $this->match('qual o horario?')->id);
        $this->assertSame($rule->id, $this->match('voces funciona amanha?')->id);
        $this->assertSame($rule->id, $this->match('Bom dia, tudo bem?')->id);
        $this->assertNull($this->match('quero um orcamento'));
    }

    // ---- Regex --------------------------------------------------------------

    public function test_regex_casa_padrao(): void
    {
        $rule = $this->ruleComGatilhos([['match_type' => 'regex', 'match_value' => '^pre[cç]o']]);

        $this->assertSame($rule->id, $this->match('preço disso?')->id);
        $this->assertSame($rule->id, $this->match('Preco do produto')->id);
        $this->assertNull($this->match('qual o valor?'));
    }

    public function test_regex_invalido_nao_quebra_nem_casa(): void
    {
        // Padrao invalido (parentese aberto). Nao deve lancar; trata como no-match.
        $this->ruleComGatilhos([['match_type' => 'regex', 'match_value' => '(abc']]);

        $this->assertNull($this->match('abc qualquer'));
        $this->assertFalse(RuleMatcher::isValidRegex('(abc'));
        $this->assertTrue(RuleMatcher::isValidRegex('^ola'));
    }

    public function test_regex_catastrofico_nao_trava(): void
    {
        // Padrao classico de backtracking catastrofico; o backtrack_limit reduzido
        // faz preg_match retornar false -> no-match, sem travar o processo.
        $this->ruleComGatilhos([['match_type' => 'regex', 'match_value' => '(a+)+$']]);

        $inicio = microtime(true);
        $resultado = $this->match(str_repeat('a', 40) . '!');
        $this->assertLessThan(2.0, microtime(true) - $inicio, 'regex demorou demais');
        $this->assertNull($resultado);
    }

    // ---- Respostas aleatorias ----------------------------------------------

    public function test_resposta_aleatoria_determinista_no_teste(): void
    {
        $rule = $this->ruleComGatilhos(
            [['match_type' => 'contains', 'match_value' => 'oi']],
            ['Resposta A', 'Resposta B', 'Resposta C'],
        );

        // Chooser deterministico: sempre o ultimo.
        $responder = new RuleResponder(fn (array $itens) => $itens[count($itens) - 1]);
        $this->assertSame('Resposta C', $responder->responseFor($rule));

        // Chooser deterministico: sempre o primeiro.
        $responder2 = new RuleResponder(fn (array $itens) => $itens[0]);
        $this->assertSame('Resposta A', $responder2->responseFor($rule));
    }

    public function test_resposta_aleatoria_sempre_do_conjunto(): void
    {
        $rule = $this->ruleComGatilhos(
            [['match_type' => 'contains', 'match_value' => 'oi']],
            ['A', 'B', 'C'],
        );
        $responder = app(RuleResponder::class);

        for ($i = 0; $i < 20; $i++) {
            $this->assertContains($responder->pick($rule), ['A', 'B', 'C']);
        }
    }

    // ---- Placeholders -------------------------------------------------------

    public function test_placeholder_nome_e_saudacao(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 29, 9, 0, 0, 'America/Sao_Paulo')); // bom dia
        $responder = app(RuleResponder::class);

        $out = $responder->render('{saudacao}, {nome}! Como vai?', ['nome' => 'Joao', 'now' => now()]);
        $this->assertSame('Bom dia, Joao! Como vai?', $out);

        Carbon::setTestNow(Carbon::create(2026, 6, 29, 15, 0, 0, 'America/Sao_Paulo')); // boa tarde
        $out = $responder->render('{saudacao}', ['now' => now()]);
        $this->assertSame('Boa tarde', $out);

        Carbon::setTestNow(Carbon::create(2026, 6, 29, 21, 0, 0, 'America/Sao_Paulo')); // boa noite
        $out = $responder->render('{saudacao}', ['now' => now()]);
        $this->assertSame('Boa noite', $out);

        Carbon::setTestNow();
    }

    public function test_placeholder_desconhecido_fica_intacto(): void
    {
        $responder = app(RuleResponder::class);
        $this->assertSame('oi {foo}', $responder->render('oi {foo}', []));
    }

    // ---- CRUD via UI --------------------------------------------------------

    public function test_ui_cria_regra_com_varios_gatilhos_e_respostas(): void
    {
        Livewire::test(Regras::class)
            ->call('novo')
            ->set('triggers.0.value', 'horario')
            ->call('addTrigger')
            ->set('triggers.1.type', 'starts_with')
            ->set('triggers.1.value', 'bom dia')
            ->set('responses.0', 'Atendo das 8h')
            ->call('addResponse')
            ->set('responses.1', 'Funcionamento 8-18h')
            ->call('save')
            ->assertSet('showForm', false)
            ->assertHasNoErrors();

        $rule = AutoReplyRule::with(['triggers', 'responses'])->first();
        $this->assertCount(2, $rule->triggers);
        $this->assertCount(2, $rule->responses);
    }

    public function test_ui_regex_invalido_bloqueia_save(): void
    {
        Livewire::test(Regras::class)
            ->call('novo')
            ->set('triggers.0.type', 'regex')
            ->set('triggers.0.value', '(abc')
            ->set('responses.0', 'x')
            ->call('save')
            ->assertHasErrors('triggers.0.value')
            ->assertSet('showForm', true);

        $this->assertDatabaseCount('auto_reply_rules', 0);
    }
}
