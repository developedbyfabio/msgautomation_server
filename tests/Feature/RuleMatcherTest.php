<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AutoReplyRule;
use App\Whatsapp\AutoReply\RuleMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RuleMatcherTest extends TestCase
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

    private function rule(string $type, string $value, int $priority = 0, bool $enabled = true): AutoReplyRule
    {
        return AutoReplyRule::create([
            'account_id' => $this->account->id,
            'match_type' => $type,
            'match_value' => $value,
            'response_text' => "resp:{$value}",
            'enabled' => $enabled,
            'priority' => $priority,
        ]);
    }

    private function match(?string $text): ?AutoReplyRule
    {
        return $this->matcher->match($this->account->id, null, $text);
    }

    public function test_exact_com_fold_de_acento_e_case(): void
    {
        $this->rule('exact', 'Horário');
        $this->assertNotNull($this->match('  horario '));   // trim + lower + fold
        $this->assertNull($this->match('horario sim'));     // exact nao casa parcial
    }

    public function test_starts_with(): void
    {
        $this->rule('starts_with', 'bom dia');
        $this->assertNotNull($this->match('Bom dia! tudo bem?'));
        $this->assertNull($this->match('oi, bom dia'));
    }

    public function test_contains_whole_word_evita_substring(): void
    {
        $this->rule('contains', 'ola');
        $this->assertNull($this->match('escola fechada'));   // "ola" dentro de "escola" NAO casa
        $this->assertNotNull($this->match('Ola, tudo bem?')); // palavra inteira casa
    }

    public function test_contains_com_fold_de_acento(): void
    {
        $this->rule('contains', 'horario');
        $this->assertNotNull($this->match('Qual o horário de funcionamento?'));
    }

    public function test_conflito_gatilho_mais_especifico_vence(): void
    {
        // Fatia 0: sem setas; resolve por especificidade. exact > contains.
        $this->rule('contains', 'preco');
        $exata = $this->rule('exact', 'preco');
        $this->assertSame($exata->id, $this->match('preco')->id);

        // Mais longo vence entre o mesmo tipo.
        $curta = $this->rule('contains', 'nota');
        $longa = $this->rule('contains', 'nota fiscal');
        $this->assertSame($longa->id, $this->match('preciso da nota fiscal hoje')->id);
        // (a 'curta' tambem casa, mas a mais longa e mais especifica)
        $this->assertNotNull($curta);
    }

    public function test_conflito_empate_mais_antiga_vence(): void
    {
        // Gatilhos identicos -> empate -> regra mais antiga (id menor) vence.
        $primeira = $this->rule('contains', 'preco');
        $this->rule('contains', 'preco');
        $this->assertSame($primeira->id, $this->match('qual o preco?')->id);
    }

    public function test_regra_desabilitada_nao_casa(): void
    {
        $this->rule('contains', 'horario', enabled: false);
        $this->assertNull($this->match('qual o horario?'));
    }

    public function test_texto_nulo_ou_vazio_nao_casa(): void
    {
        $this->rule('contains', 'horario');
        $this->assertNull($this->match(null));
        $this->assertNull($this->match('   '));
    }
}
