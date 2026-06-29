<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AutoReplyRule;
use App\Whatsapp\AutoReply\RuleConflictDetector;
use App\Whatsapp\AutoReply\RuleMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fatia 0 — detector de sobreposicao + auto-resolucao (allMatching).
 */
class RuleConflictTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'T']);
    }

    private function rule(string $type, string $value): AutoReplyRule
    {
        return AutoReplyRule::create([
            'account_id' => $this->account->id, 'match_type' => $type, 'match_value' => $value,
            'response_text' => 'r:' . $value, 'enabled' => true,
        ]);
    }

    public function test_detecta_sobreposicao_entre_regras(): void
    {
        // "preco" (contains) e "qual o preco" (contains): mensagem "qual o preco?" casa as duas.
        $a = $this->rule('contains', 'preco');
        $b = $this->rule('contains', 'qual o preco');

        $conf = app(RuleConflictDetector::class)->conflicts($this->account->id);

        $this->assertArrayHasKey($a->id, $conf);
        $this->assertArrayHasKey($b->id, $conf);
    }

    public function test_sem_sobreposicao_nao_marca(): void
    {
        $this->rule('contains', 'wifi');
        $this->rule('contains', 'horario');

        $conf = app(RuleConflictDetector::class)->conflicts($this->account->id);

        $this->assertSame([], $conf);
    }

    public function test_all_matching_ordena_vencedora_primeiro(): void
    {
        $contains = $this->rule('contains', 'preco');
        $exata = $this->rule('exact', 'preco');

        $matching = app(RuleMatcher::class)->allMatching($this->account->id, null, 'preco');

        $this->assertSame($exata->id, $matching[0]->id);   // exact vence
        $this->assertSame($contains->id, $matching[1]->id); // contains perde
    }
}
