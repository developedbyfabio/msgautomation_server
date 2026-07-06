<?php

namespace Tests\Feature;

use App\Livewire\Variaveis;
use App\Models\Account;
use App\Models\Variable;
use App\Tenancy\AccountContext;
use App\Variables\InstantiateVariableTemplate;
use App\Variables\VariableTemplateCatalog;
use App\Whatsapp\AutoReply\RuleResponder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fatia 14 — templates de VARIAVEL: catalogo em codigo + instanciacao pelo
 * VariableWriter oficial. Todas 'static' com [placeholder]. Nome duplicado NAO
 * sufixa (nome e identidade de referencia {empresa}): o caminho oficial rejeita
 * e o motivo vai pro toast — comportamento registrado da fatia.
 */
class VariableTemplateTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'A']);
        app(AccountContext::class)->set($this->account->id);
    }

    public function test_integridade_todos_os_templates_instanciam_resolviveis(): void
    {
        $catalog = app(VariableTemplateCatalog::class);
        $service = app(InstantiateVariableTemplate::class);

        $this->assertCount(3, $catalog->all()); // 3 variaveis do design

        foreach ($catalog->all() as $key => $template) {
            $res = $service->handle($key, $this->account->id);

            $this->assertSame([], $res['errors']);
            $v = $res['variable'];
            $this->assertSame($this->account->id, (int) $v->account_id);
            $this->assertSame('static', $v->type);
            $this->assertTrue((bool) $v->active);
            $this->assertFalse((bool) $v->is_system);

            // RESOLVIVEL na estrutura real: o renderizador OFICIAL do envio
            // substitui {nome_da_variavel} pelo valor do template.
            $render = app(RuleResponder::class)->render('{' . $template['name'] . '}');
            $this->assertSame($template['config']['valor'], $render);
        }

        $this->assertSame(3, Variable::withoutAccountScope()->where('is_system', false)->count());
    }

    public function test_nome_duplicado_e_rejeitado_pelo_caminho_oficial_sem_sobrescrever(): void
    {
        // O usuario ja tem a {empresa} com valor REAL.
        app(\App\Variables\VariableWriter::class)->save($this->account->id, [
            'name' => 'empresa', 'type' => 'static', 'config' => ['valor' => 'Padaria do Bairro'],
        ]);

        // Template 'empresa': o writer rejeita duplicata; NADA e sobrescrito.
        Livewire::test(Variaveis::class)->call('usarTemplate', 'empresa');

        $this->assertSame(1, Variable::withoutAccountScope()->where('name', 'empresa')->count());
        $v = Variable::query()->where('name', 'empresa')->firstOrFail();
        $this->assertSame('Padaria do Bairro', $v->config['valor']); // valor REAL intacto
    }

    public function test_ui_usar_template_cria_e_abre_o_form(): void
    {
        Livewire::test(Variaveis::class)
            ->call('usarTemplate', 'atendente')
            ->assertSet('showForm', true)
            ->assertSet('vName', 'atendente');

        $this->assertSame(1, Variable::withoutAccountScope()->where('is_system', false)->count());
    }

    public function test_key_invalida_erro_claro_sem_efeito(): void
    {
        Livewire::test(Variaveis::class)->call('usarTemplate', 'nao-existe');
        $this->assertSame(0, Variable::withoutAccountScope()->where('is_system', false)->count());

        $this->expectException(\InvalidArgumentException::class);
        app(InstantiateVariableTemplate::class)->handle('nao-existe', $this->account->id);
    }

    public function test_isolamento_instanciar_em_a_nao_cria_nada_em_b(): void
    {
        $b = Account::create(['name' => 'B']);

        app(InstantiateVariableTemplate::class)->handle('empresa', $this->account->id);

        $this->assertSame(0, Variable::withoutAccountScope()->where('account_id', $b->id)->where('is_system', false)->count());
        $this->assertSame(1, Variable::withoutAccountScope()->where('account_id', $this->account->id)->where('is_system', false)->count());
    }
}
