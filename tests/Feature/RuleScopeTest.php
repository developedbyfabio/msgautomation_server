<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AutoReplyRule;
use App\Models\Contact;
use App\Whatsapp\AutoReply\RuleMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S3 — escopo por contato. Regra 'contatos' so dispara para os contatos da lista;
 * 'global' para todos os aprovados.
 */
class RuleScopeTest extends TestCase
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

    private function rule(string $scope): AutoReplyRule
    {
        $rule = AutoReplyRule::create([
            'account_id' => $this->account->id,
            'match_type' => 'contains', 'match_value' => 'oi', 'response_text' => 'ola',
            'enabled' => true, 'priority' => 0, 'scope' => $scope,
        ]);
        $rule->triggers()->create(['match_type' => 'contains', 'match_value' => 'oi']);
        $rule->responses()->create(['response_text' => 'ola']);

        return $rule;
    }

    private function contact(string $jid): Contact
    {
        return Contact::create(['account_id' => $this->account->id, 'remote_jid' => $jid, 'auto_reply_mode' => 'on']);
    }

    public function test_global_casa_qualquer_remetente(): void
    {
        $rule = $this->rule('global');

        $this->assertSame($rule->id, $this->matcher->match($this->account->id, null, 'oi', 'aaa@s.whatsapp.net')?->id);
        $this->assertSame($rule->id, $this->matcher->match($this->account->id, null, 'oi', 'bbb@s.whatsapp.net')?->id);
    }

    public function test_contatos_casa_so_quem_esta_na_lista(): void
    {
        $rule = $this->rule('contatos');
        $alvo = $this->contact('alvo@s.whatsapp.net');
        $this->contact('outro@s.whatsapp.net');
        $rule->contacts()->attach($alvo->id);

        // Remetente na lista -> casa.
        $this->assertSame($rule->id, $this->matcher->match($this->account->id, null, 'oi', 'alvo@s.whatsapp.net')?->id);
        // Fora da lista -> nao casa.
        $this->assertNull($this->matcher->match($this->account->id, null, 'oi', 'outro@s.whatsapp.net'));
    }

    public function test_contatos_sem_remetente_nao_elegivel(): void
    {
        $rule = $this->rule('contatos');
        $alvo = $this->contact('alvo@s.whatsapp.net');
        $rule->contacts()->attach($alvo->id);

        // Sem jid (ex.: dry-run sem contato) -> regra de escopo 'contatos' nao entra.
        $this->assertNull($this->matcher->match($this->account->id, null, 'oi', null));
    }

    public function test_global_e_contatos_convivem_por_prioridade(): void
    {
        // Regra de contato (prioridade maior = priority menor) vence pro alvo;
        // global atende o resto.
        $contatoRule = $this->rule('contatos');
        $contatoRule->update(['priority' => 0]);
        $alvo = $this->contact('alvo@s.whatsapp.net');
        $contatoRule->contacts()->attach($alvo->id);

        $globalRule = $this->rule('global');
        $globalRule->update(['priority' => 1]);

        $this->assertSame($contatoRule->id, $this->matcher->match($this->account->id, null, 'oi', 'alvo@s.whatsapp.net')?->id);
        $this->assertSame($globalRule->id, $this->matcher->match($this->account->id, null, 'oi', 'qualquer@s.whatsapp.net')?->id);
    }
}
