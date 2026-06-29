<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AutoReplyRule;
use App\Whatsapp\AutoReply\RuleMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S5 — match tolerante (fuzzy), opt-in por gatilho, conservador, com guarda-corpos.
 * Levenshtein por token whole-word. Padrao = exato (comportamento atual preservado).
 */
class FuzzyMatchTest extends TestCase
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

    private function rule(string $type, string $value, string $precision = 'exato', ?string $level = null): AutoReplyRule
    {
        $rule = AutoReplyRule::create([
            'account_id' => $this->account->id, 'match_type' => $type, 'match_value' => $value,
            'response_text' => 'ok', 'enabled' => true, 'priority' => 0,
        ]);
        $rule->triggers()->create([
            'match_type' => $type, 'match_value' => $value,
            'precision' => $precision, 'fuzzy_level' => $precision === 'tolerante' ? ($level ?? 'media') : null,
        ]);
        $rule->responses()->create(['response_text' => 'ok']);

        return $rule;
    }

    private function match(?string $text): ?AutoReplyRule
    {
        return $this->matcher->match($this->account->id, null, $text);
    }

    public function test_tolerante_casa_erro_de_digitacao(): void
    {
        $rule = $this->rule('contains', 'senha wifi', 'tolerante', 'media');

        $this->assertSame($rule->id, $this->match('Qual a Snha wifi?')?->id, '"Snha wifi" deveria casar');
        $this->assertSame($rule->id, $this->match('senha wifi')?->id, 'exato tambem casa');
        $this->assertSame($rule->id, $this->match('me passa a senh wifi por favor')?->id, '"senh" (faltou a)');
    }

    public function test_tolerante_nao_casa_generico(): void
    {
        $this->rule('contains', 'senha wifi', 'tolerante', 'media');

        $this->assertNull($this->match('bom dia, tudo bem?'), 'frase generica nao casa');
        $this->assertNull($this->match('qual o horario?'), 'outra coisa nao casa');
        $this->assertNull($this->match('me ve a senha'), 'so um token (sem "wifi") nao casa');
    }

    public function test_gatilho_curto_segue_exato_mesmo_tolerante(): void
    {
        // "oi" tem 2 chars (< 4): guarda-corpo forca exato mesmo marcado tolerante.
        $rule = $this->rule('contains', 'oi', 'tolerante', 'alta');

        $this->assertSame($rule->id, $this->match('oi, bom dia')?->id);
        $this->assertNull($this->match('ai que dia')); // "ai" NAO casa "oi" (curto = exato)
    }

    public function test_exato_default_nao_e_fuzzy(): void
    {
        $this->rule('contains', 'senha', 'exato'); // precision padrao

        $this->assertNull($this->match('me passa a snha'), 'exato nao tolera erro');
        $this->assertNotNull($this->match('qual a senha?'));
    }

    public function test_tolerante_preserva_whole_word(): void
    {
        // "preco" tolerante nao deve casar dentro de "precoteste" (token diferente, poda por tamanho).
        $rule = $this->rule('contains', 'preco', 'tolerante', 'media');

        $this->assertNull($this->match('precoteste do produto'));
        $this->assertSame($rule->id, $this->match('qual o preco?')?->id);
        $this->assertSame($rule->id, $this->match('qual o prco?')?->id); // 1 erro
    }

    public function test_nivel_baixa_e_mais_rigoroso_que_alta(): void
    {
        // "orcamento" (9): baixa allowed = intdiv(9,6)=1; alta = min(2,intdiv(9,3)=3)=2.
        $baixa = $this->rule('contains', 'orcamento', 'tolerante', 'baixa');
        // "orcamen" tem 2 erros (faltam "to") -> baixa NAO casa.
        $this->assertNull($this->matcher->match($this->account->id, null, 'quero um orcamen'));

        $baixa->triggers()->update(['fuzzy_level' => 'alta']);
        // alta tolera 2 -> casa.
        $this->assertSame($baixa->id, $this->matcher->match($this->account->id, null, 'quero um orcamen')?->id);
    }

    public function test_exact_tolerante_alinha_tokens(): void
    {
        $rule = $this->rule('exact', 'senha wifi', 'tolerante', 'media');

        $this->assertSame($rule->id, $this->match('snha wifi')?->id);
        $this->assertNull($this->match('snha wifi agora'), 'exact exige mesma qtd de tokens');
    }
}
