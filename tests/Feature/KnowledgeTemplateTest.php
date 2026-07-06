<?php

namespace Tests\Feature;

use App\Ai\InstantiateKnowledgeTemplate;
use App\Ai\KnowledgeTemplateCatalog;
use App\Livewire\Conhecimento;
use App\Models\Account;
use App\Models\Knowledge;
use App\Tenancy\AccountContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fatia 14 — templates de CONHECIMENTO: catalogo em codigo + instanciacao pelo
 * KnowledgeWriter oficial. Conteudo passivo com [placeholders], nasce active
 * (nao dispara nada sozinho); titulo sufixado em colisao (mecanismo Fatia 7).
 */
class KnowledgeTemplateTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'A']);
        app(AccountContext::class)->set($this->account->id);
    }

    public function test_integridade_todos_os_templates_instanciam_validos(): void
    {
        $catalog = app(KnowledgeTemplateCatalog::class);
        $service = app(InstantiateKnowledgeTemplate::class);

        $this->assertCount(4, $catalog->all()); // 4 conhecimentos do design

        foreach ($catalog->all() as $key => $template) {
            $k = $service->handle($key, $this->account->id);

            $this->assertSame($this->account->id, (int) $k->account_id);
            $this->assertSame($template['name'], $k->title);
            $this->assertNotSame('', trim((string) $k->content));
            $this->assertSame('low', $k->sensitivity);
            $this->assertTrue((bool) $k->active);
        }

        $this->assertSame(4, Knowledge::withoutAccountScope()->count());
    }

    public function test_colisao_de_titulo_sufixa_incremental(): void
    {
        app(InstantiateKnowledgeTemplate::class)->handle('horario', $this->account->id);
        $segunda = app(InstantiateKnowledgeTemplate::class)->handle('horario', $this->account->id);

        $this->assertSame('Horário de funcionamento (2)', $segunda->title);
    }

    public function test_ui_usar_template_cria_e_abre_o_form(): void
    {
        Livewire::test(Conhecimento::class)
            ->call('usarTemplate', 'pagamento')
            ->assertSet('showForm', true)
            ->assertSet('title', 'Formas de pagamento');

        $this->assertSame(1, Knowledge::withoutAccountScope()->count());
    }

    public function test_key_invalida_erro_claro_sem_efeito(): void
    {
        Livewire::test(Conhecimento::class)->call('usarTemplate', 'nao-existe');
        $this->assertSame(0, Knowledge::withoutAccountScope()->count());

        $this->expectException(\InvalidArgumentException::class);
        app(InstantiateKnowledgeTemplate::class)->handle('nao-existe', $this->account->id);
    }

    public function test_isolamento_instanciar_em_a_nao_cria_nada_em_b(): void
    {
        $b = Account::create(['name' => 'B']);

        app(InstantiateKnowledgeTemplate::class)->handle('endereco', $this->account->id);

        $this->assertSame(0, Knowledge::withoutAccountScope()->where('account_id', $b->id)->count());
        $this->assertSame(1, Knowledge::withoutAccountScope()->where('account_id', $this->account->id)->count());
    }
}
