<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Prompt 07 — menu lateral (sidebar). Smoke de navegacao: o layout novo (flux:sidebar +
 * header slim com breadcrumb) renderiza em TODAS as abas do menu sem quebrar nenhuma rota.
 * Nao testa visual/responsividade (isso e checklist manual no relatorio) — testa que a
 * troca de navegacao nao derrubou nenhuma pagina.
 */
class NavegacaoSidebarTest extends TestCase
{
    use RefreshDatabase;

    /** Mesmos itens (rota => rotulo) do $nav do layout — ordem do menu. */
    private const MENU = [
        'painel' => 'Painel',
        'conversas' => 'Conversas',
        'kanban' => 'Kanban',
        'contatos' => 'Contatos',
        'senhas' => 'Senhas',
        'variaveis' => 'Variaveis',
        'regras' => 'Regras',
        'fluxos' => 'Fluxos',
        'conhecimento' => 'Conhecimento',
        'revisao' => 'Revisao',
        'campanhas' => 'Campanhas',
        'logs' => 'Logs',
        'configuracoes' => 'Configuracoes',
        'perfil' => 'Perfil',
    ];

    private function operador(): User
    {
        // MT-0: como em producao, a conta existe antes da UI (seeder).
        $account = Account::firstOrCreate(['name' => 'T']);
        // Prompt 27 (Fatia 2): conta ONBOARDED (canal conectado) — o gate manda
        // conta SEM canal pra /conexao.
        \App\Models\Channel::withoutAccountScope()->firstOrCreate(
            ['account_id' => $account->id],
            ['instance' => 'conta-' . $account->id . '-t', 'provider' => 'evolution', 'webhook_token' => 'tok-t', 'status' => 'connected'],
        );

        return User::create([
            'name' => 'Operador',
            'email' => 'op@exemplo.local',
            'password' => Hash::make('segredo-forte-123'),
        ]);
    }

    public function test_todas_as_abas_do_menu_respondem_200_logado(): void
    {
        $this->actingAs($this->operador());

        foreach (self::MENU as $rota => $rotulo) {
            $this->get(route($rota))->assertOk()->assertSee($rotulo);
        }
    }

    public function test_layout_traz_sidebar_e_breadcrumb_de_contexto(): void
    {
        $this->actingAs($this->operador());

        $resp = $this->get(route('conversas'))->assertOk();

        // Sidebar Flux colapsavel presente (custom element do flux-lite).
        $resp->assertSee('<ui-sidebar', false);
        $resp->assertSee('collapsible', false);
        // Fatia 10: o header mostra SO o titulo da aba atual — o prefixo "Menu >"
        // saiu (nunca foi link; a navegacao vive na sidebar).
        $resp->assertSee('data-flux-breadcrumbs', false);
        $resp->assertDontSee('Menu');
        // Cluster preservado: robo ON/OFF e botao Sair continuam no header.
        $resp->assertSee('Robo:');
        $resp->assertSee('Sair');
        // Prompt 30: toggle de tema presente no header (alterna via $flux.appearance).
        $resp->assertSee('Alternar tema claro/escuro');
        $resp->assertSee('$flux.appearance', false);
    }

    /** Fatia 10 — breadcrumb SO com o titulo da pagina, em todas as abas do menu. */
    public function test_breadcrumb_mostra_so_o_titulo_sem_prefixo_menu(): void
    {
        $this->actingAs($this->operador());

        // Amostra com paginas de conteudo variado (o rotulo aparece; "Menu" nao).
        foreach (['regras' => 'Regras', 'senhas' => 'Senhas', 'configuracoes' => 'Configuracoes'] as $rota => $rotulo) {
            $this->get(route($rota))->assertOk()->assertSee($rotulo)->assertDontSee('Menu');
        }
    }

    public function test_abas_do_menu_bloqueadas_sem_login(): void
    {
        foreach (array_keys(self::MENU) as $rota) {
            $this->get(route($rota))->assertRedirect(route('login'));
        }
    }
}
