<?php

namespace Tests\Feature;

use App\Livewire\Regras;
use App\Models\Account;
use App\Models\AutoReplyRule;
use App\Tenancy\AccountContext;
use App\Whatsapp\AutoReply\InstantiateRuleTemplate;
use App\Whatsapp\AutoReply\RuleTemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fatia 14 — templates de REGRA: catalogo em codigo + instanciacao pelo
 * RuleWriter oficial. Toda regra de template NASCE DESABILITADA (placeholders
 * [editaveis] no texto). Colisao de gatilho NAO e bloqueada pelo caminho
 * oficial (comportamento registrado e provado aqui): a copia nasce OFF e o
 * original fica intacto — sem disputa ate o usuario ligar.
 */
class RuleTemplateTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'A']);
        app(AccountContext::class)->set($this->account->id);
    }

    public function test_integridade_todos_os_templates_do_catalogo_instanciam_validos(): void
    {
        $catalog = app(RuleTemplateCatalog::class);
        $service = app(InstantiateRuleTemplate::class);

        $this->assertCount(5, $catalog->all()); // 5 regras do design

        foreach ($catalog->all() as $key => $template) {
            $rule = $service->handle($key, $this->account->id);

            $this->assertSame($this->account->id, (int) $rule->account_id);
            $this->assertFalse((bool) $rule->enabled, "template '{$key}' nasceu LIGADO");
            $this->assertSame(count($template['triggers']), $rule->triggers()->count());
            $this->assertSame(count($template['responses']), $rule->responses()->count());
            // MATCH-1: normalized_text persistido em toda escrita via writer.
            foreach ($rule->triggers()->get() as $t) {
                $this->assertNotNull($t->normalized_text, "trigger sem normalized_text no template '{$key}'");
            }
        }

        $this->assertSame(5, AutoReplyRule::withoutAccountScope()->count());
    }

    public function test_ui_usar_template_cria_desligada_e_abre_o_form(): void
    {
        Livewire::test(Regras::class)
            ->call('usarTemplate', 'boas_vindas')
            ->assertSet('showForm', true)   // abriu pra edicao (trocar placeholders)
            ->assertSet('enabled', false);  // form reflete o estado OFF

        $rule = AutoReplyRule::query()->firstOrFail();
        $this->assertFalse((bool) $rule->enabled);
        $this->assertStringContainsString('[nome da empresa]', $rule->response_text);
    }

    public function test_colisao_de_gatilho_com_regra_existente_cria_off_sem_tocar_a_original(): void
    {
        // Regra REAL do usuario, LIGADA, com gatilho que o template 'horario' tambem usa.
        $existente = AutoReplyRule::create([
            'account_id' => $this->account->id, 'match_type' => 'contains', 'match_value' => 'horário',
            'response_text' => 'Das 9h as 17h (regra do usuario).', 'enabled' => true, 'priority' => 0,
        ]);
        $existente->triggers()->create(['match_type' => 'contains', 'match_value' => 'horário']);
        $existente->responses()->create(['response_text' => 'Das 9h as 17h (regra do usuario).']);

        app(InstantiateRuleTemplate::class)->handle('horario', $this->account->id);

        // Caminho oficial NAO bloqueia a colisao: a copia existe, mas OFF —
        // zero disputa de matching ate o usuario habilitar conscientemente.
        $this->assertSame(2, AutoReplyRule::withoutAccountScope()->count());
        $template = AutoReplyRule::query()->where('enabled', false)->firstOrFail();
        $this->assertStringContainsString('[dias e horários]', $template->response_text);
        // Original INTACTA (ligada, mesmo texto).
        $existente->refresh();
        $this->assertTrue((bool) $existente->enabled);
        $this->assertSame('Das 9h as 17h (regra do usuario).', $existente->response_text);
    }

    public function test_instanciar_2x_cria_duas_regras_off_comportamento_oficial(): void
    {
        // Regra nao tem NOME no model — nao ha o que sufixar; o caminho oficial
        // cria outra regra (ambas visiveis na lista, ambas OFF).
        app(InstantiateRuleTemplate::class)->handle('agradecimento', $this->account->id);
        app(InstantiateRuleTemplate::class)->handle('agradecimento', $this->account->id);

        $this->assertSame(2, AutoReplyRule::withoutAccountScope()->count());
        $this->assertSame(0, AutoReplyRule::withoutAccountScope()->where('enabled', true)->count());
    }

    public function test_key_invalida_erro_claro_sem_efeito(): void
    {
        Livewire::test(Regras::class)->call('usarTemplate', 'nao-existe');
        $this->assertSame(0, AutoReplyRule::withoutAccountScope()->count());

        $this->expectException(\InvalidArgumentException::class);
        app(InstantiateRuleTemplate::class)->handle('nao-existe', $this->account->id);
    }

    public function test_isolamento_instanciar_em_a_nao_cria_nada_em_b(): void
    {
        $b = Account::create(['name' => 'B']);

        app(InstantiateRuleTemplate::class)->handle('boas_vindas', $this->account->id);

        $this->assertSame(0, AutoReplyRule::withoutAccountScope()->where('account_id', $b->id)->count());
        $this->assertSame(1, AutoReplyRule::withoutAccountScope()->where('account_id', $this->account->id)->count());
    }
}
