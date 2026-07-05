<?php

namespace Tests\Feature;

use App\Livewire\StatusConexao;
use App\Models\Account;
use App\Models\Channel;
use App\Tenancy\AccountContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fatia 10 — pulso do indicador de conexao: animate-ping (motion-safe) SO no
 * estado conectado; desconectado/sem canal renderizam sem animacao (nenhum
 * pulso enganoso). Apresentacao apenas — a logica de refresh/polling e a do
 * StatusConexaoIsolamentoTest, que segue intacto.
 */
class StatusConexaoPulseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.evolution.base_url' => 'http://evo-test:8090',
            'services.evolution.api_key' => 'chave-evo',
        ]);
    }

    private function contaComCanal(string $estadoEvolution): void
    {
        $a = Account::create(['name' => 'T']);
        app(AccountContext::class)->set($a->id);
        Channel::create([
            'account_id' => $a->id, 'instance' => 'conta-' . $a->id . '-x', 'provider' => 'evolution',
            'webhook_token' => 'tok', 'status' => 'disconnected',
            'credentials' => ['base_url' => 'http://evo-test:8090', 'apikey' => 'k', 'instance' => 'conta-' . $a->id . '-x'],
        ]);
        Http::fake([
            'evo-test:8090/instance/connectionState/*' => Http::response(['instance' => ['state' => $estadoEvolution]], 200),
        ]);
    }

    public function test_conectado_renderiza_com_pulso_motion_safe(): void
    {
        $this->contaComCanal('open');

        Livewire::test(StatusConexao::class)
            ->call('refresh')
            ->assertSet('state', 'open')
            ->assertSee('conectado')
            ->assertSee('motion-safe:animate-ping');
    }

    public function test_desconectado_renderiza_sem_pulso(): void
    {
        $this->contaComCanal('close');

        Livewire::test(StatusConexao::class)
            ->call('refresh')
            ->assertSet('state', 'close')
            ->assertSee('desconectado')
            ->assertDontSee('animate-ping');
    }

    public function test_sem_canal_renderiza_sem_pulso(): void
    {
        $a = Account::create(['name' => 'S']);
        app(AccountContext::class)->set($a->id);
        Http::fake();

        Livewire::test(StatusConexao::class)
            ->call('refresh')
            ->assertSet('state', 'sem_canal')
            ->assertSee('sem canal')
            ->assertDontSee('animate-ping');
    }
}
