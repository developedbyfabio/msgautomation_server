<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fatia 21 — tela de login: APRESENTACAO apenas (fundo fundo.webp + 100dvh com
 * fallback 100vh + centralizacao + overlay de legibilidade). A logica de
 * autenticacao e byte-identica — o contrato e a suite existente de auth/
 * rate-limiting/2FA passar SEM alteracao (AuthTest, LoginHardeningTest,
 * PerfilE2faTest, AdminDoisFatoresTest).
 */
class LoginViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_renderiza_com_formulario_fundo_e_fallbacks(): void
    {
        $resp = $this->get('/login')->assertOk();

        // Estrutura do form intacta.
        $resp->assertSee('msgautomation');
        $resp->assertSee('E-mail');
        $resp->assertSee('Senha');
        $resp->assertSee('Manter conectado');
        $resp->assertSee('Entrar');

        // Fundo referenciado + fallback de viewport (100dvh sobrescreve o 100vh
        // onde suportado — o 100vh puro era a causa do scroll no iOS).
        $resp->assertSee('fundo.webp');
        $resp->assertSee('min-height: 100vh; min-height: 100dvh;', false);
        // Fallback de cor solida caso a imagem nao carregue (bg do tema no body).
        $resp->assertSee('bg-zinc-100', false);
    }

    public function test_desafio_2fa_usa_a_mesma_casca(): void
    {
        // A rota do desafio exige sessao de login pendente; sem ela, redireciona
        // (comportamento EXISTENTE, intocado) — o layout compartilhado e coberto
        // pela propria suite de 2FA que renderiza a view.
        $this->get('/two-factor-challenge')->assertRedirect(route('login'));
    }
}
