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
 * Prompt 27, Fatia 1 — StatusConexao escopado + guarda de canal null. Conta SEM
 * canal nunca le/age sobre a instancia global do .env (canal do Fabio). Conta COM
 * canal segue como antes.
 */
class StatusConexaoIsolamentoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.evolution.base_url' => 'http://evo-test:8090',
            'services.evolution.api_key' => 'chave-evo',
            'services.evolution.instance' => 'fabio-pessoal', // instancia GLOBAL do .env
        ]);
    }

    private function conta(string $nome): Account
    {
        $a = Account::create(['name' => $nome]);
        app(AccountContext::class)->set($a->id);

        return $a;
    }

    public function test_conta_sem_canal_status_e_sem_canal_e_nao_chama_evolution(): void
    {
        Http::fake(); // qualquer chamada HTTP = falha o teste
        $this->conta('Sem Canal');

        Livewire::test(StatusConexao::class)
            ->call('refresh')
            ->assertSet('state', 'sem_canal');

        Http::assertNothingSent(); // NAO tocou a instancia global
    }

    public function test_conta_sem_canal_logout_e_noop_nao_desconecta_instancia_global(): void
    {
        Http::fake();
        $this->conta('Sem Canal');

        Livewire::test(StatusConexao::class)
            ->call('disconnectConfirmed')
            ->assertSet('state', 'sem_canal');

        Http::assertNothingSent(); // NUNCA chamou logout do fabio-pessoal
    }

    public function test_conta_sem_canal_abrir_qr_redireciona_e_nao_mostra_qr_alheio(): void
    {
        Http::fake();
        $this->conta('Sem Canal');

        Livewire::test(StatusConexao::class)
            ->call('abrirQr')
            ->assertRedirect(route('conexao'))
            ->assertSet('qr', null);

        Http::assertNothingSent();
    }

    public function test_conta_com_canal_le_o_proprio_estado(): void
    {
        $a = $this->conta('Com Canal');
        Channel::create([
            'account_id' => $a->id, 'instance' => 'conta-' . $a->id . '-x', 'provider' => 'evolution',
            'webhook_token' => 'tok', 'status' => 'disconnected',
            'credentials' => ['base_url' => 'http://evo-test:8090', 'apikey' => 'k', 'instance' => 'conta-' . $a->id . '-x'],
        ]);
        Http::fake([
            'evo-test:8090/instance/connectionState/*' => Http::response(['instance' => ['state' => 'open']], 200),
        ]);

        Livewire::test(StatusConexao::class)->call('refresh')->assertSet('state', 'open');
        // sincronizou o canal DA CONTA
        $this->assertSame('connected', Channel::withoutAccountScope()->where('account_id', $a->id)->first()->status);
    }

    public function test_isolamento_B_sem_canal_nao_toca_o_canal_de_A(): void
    {
        // A com canal (o "Fabio"); B sem canal.
        $a = Account::create(['name' => 'A']);
        Channel::create([
            'account_id' => $a->id, 'instance' => 'fabio-pessoal', 'provider' => 'evolution',
            'webhook_token' => 'tok-a', 'status' => 'connected',
            'credentials' => ['base_url' => 'http://evo-test:8090', 'apikey' => 'k', 'instance' => 'fabio-pessoal'],
        ]);
        $b = $this->conta('B'); // contexto ativo = B (sem canal)

        Http::fake(); // nenhuma chamada deve sair p/ B
        Livewire::test(StatusConexao::class)
            ->call('refresh')->assertSet('state', 'sem_canal')
            ->call('disconnectConfirmed')->assertSet('state', 'sem_canal');

        Http::assertNothingSent();
        // canal de A intacto
        $this->assertSame('connected', Channel::withoutAccountScope()->where('account_id', $a->id)->first()->status);
    }
}
