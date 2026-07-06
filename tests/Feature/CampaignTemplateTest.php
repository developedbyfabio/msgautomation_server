<?php

namespace Tests\Feature;

use App\Livewire\Campanhas;
use App\Models\Account;
use App\Models\CampaignTarget;
use App\Models\ProactiveCampaign;
use App\Tenancy\AccountContext;
use App\Whatsapp\Proactive\CampaignTemplateCatalog;
use App\Whatsapp\Proactive\InstantiateCampaignTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fatia 14 — templates de CAMPANHA: catalogo em codigo + rascunho LIMPO
 * (draft, publico vazio, sem agenda, rodape opt-out da conta com fallback).
 * NUNCA despacha job nem cria target — preview/aprovacao/disparo continuam
 * sendo o ciclo P-2/P-3.
 */
class CampaignTemplateTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'A']);
        app(AccountContext::class)->set($this->account->id);
    }

    public function test_integridade_todos_os_templates_instanciam_draft_limpo(): void
    {
        $catalog = app(CampaignTemplateCatalog::class);
        $service = app(InstantiateCampaignTemplate::class);

        $this->assertCount(3, $catalog->all()); // 3 campanhas do design

        foreach ($catalog->all() as $key => $template) {
            $c = $service->handle($key, $this->account->id);

            $this->assertSame($this->account->id, (int) $c->account_id);
            $this->assertSame('draft', $c->status);
            $this->assertSame($template['message'], $c->message);
            // Rodape opt-out SEMPRE presente (da conta, ou fallback com {palavra_sair}).
            $this->assertNotSame('', trim((string) $c->optout_footer));
            $this->assertNull($c->start_at);
            $this->assertNull($c->approved_at);
            $this->assertNull($c->approved_by);
            $this->assertSame(0, CampaignTarget::query()->where('campaign_id', $c->id)->count());
        }

        $this->assertSame(3, ProactiveCampaign::withoutAccountScope()->count());
    }

    public function test_instanciar_nao_despacha_nenhum_job(): void
    {
        Queue::fake();

        Livewire::test(Campanhas::class)->call('usarTemplate', 'promocao');

        Queue::assertNothingPushed(); // template NUNCA dispara/agenda
        $this->assertSame('draft', ProactiveCampaign::query()->firstOrFail()->status);
    }

    public function test_colisao_de_nome_sufixa_incremental(): void
    {
        app(InstantiateCampaignTemplate::class)->handle('promocao', $this->account->id);
        $segunda = app(InstantiateCampaignTemplate::class)->handle('promocao', $this->account->id);

        $this->assertSame('Promoção (2)', $segunda->name);
    }

    public function test_ui_usar_template_abre_o_form_de_edicao(): void
    {
        Livewire::test(Campanhas::class)
            ->call('usarTemplate', 'reativacao')
            ->assertSet('showForm', true)
            ->assertSet('cName', 'Reativação');

        $this->assertSame(1, ProactiveCampaign::withoutAccountScope()->count());
    }

    public function test_key_invalida_erro_claro_sem_efeito(): void
    {
        Livewire::test(Campanhas::class)->call('usarTemplate', 'nao-existe');
        $this->assertSame(0, ProactiveCampaign::withoutAccountScope()->count());

        $this->expectException(\InvalidArgumentException::class);
        app(InstantiateCampaignTemplate::class)->handle('nao-existe', $this->account->id);
    }

    public function test_isolamento_instanciar_em_a_nao_cria_nada_em_b(): void
    {
        $b = Account::create(['name' => 'B']);

        app(InstantiateCampaignTemplate::class)->handle('comunicado', $this->account->id);

        $this->assertSame(0, ProactiveCampaign::withoutAccountScope()->where('account_id', $b->id)->count());
        $this->assertSame(1, ProactiveCampaign::withoutAccountScope()->where('account_id', $this->account->id)->count());
    }
}
