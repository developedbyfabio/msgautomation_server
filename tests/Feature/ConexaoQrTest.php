<?php

namespace Tests\Feature;

use App\Livewire\Conexao;
use App\Livewire\StatusConexao;
use App\Models\Account;
use App\Models\Channel;
use App\Tenancy\AccountContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 31 — QR unificado (painel <x-qr-panel>): "Atualizar" re-busca o QR da
 * instancia (novo connect), sem reprovisionar; escopado a conta ativa. Modal na
 * /conexao e no /conversas.
 */
class ConexaoQrTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.evolution.base_url' => 'http://evo-test:8090',
            'services.evolution.api_key' => 'k',
        ]);
    }

    private function contaComCanal(): array
    {
        $a = Account::create(['name' => 'A']);
        app(AccountContext::class)->set($a->id);
        $c = Channel::create([
            'account_id' => $a->id, 'instance' => 'conta-' . $a->id . '-x', 'provider' => 'evolution',
            'webhook_token' => 'tok', 'status' => 'disconnected',
            'credentials' => ['base_url' => 'http://evo-test:8090', 'apikey' => 'k', 'instance' => 'conta-' . $a->id . '-x'],
        ]);

        return [$a, $c];
    }

    public function test_atualizar_rebusca_um_qr_novo_da_instancia(): void
    {
        [$a, $c] = $this->contaComCanal();
        Http::fake([
            '*instance/connectionState/*' => Http::response(['instance' => ['state' => 'connecting']], 200),
            '*instance/connect/*' => Http::sequence()
                ->push(['base64' => 'QR-A'], 200)
                ->push(['base64' => 'QR-B'], 200)
                ->whenEmpty(Http::response(['base64' => 'QR-B'], 200)),
        ]);

        $tela = Livewire::test(Conexao::class); // mount->poll->gerarQr = QR-A
        $tela->assertSet('qr', 'data:image/png;base64,QR-A');

        // "Atualizar" -> novo connect -> QR-B (prova que regenera).
        $tela->call('gerarQr')->assertSet('qr', 'data:image/png;base64,QR-B');

        // bateu no /instance/connect da INSTANCIA da conta (escopo).
        Http::assertSent(fn ($req) => str_contains($req->url(), '/instance/connect/conta-' . $a->id . '-x'));
    }

    public function test_atualizar_nao_reprovisiona_nem_cria_instancia(): void
    {
        [$a, $c] = $this->contaComCanal();
        Http::fake([
            '*instance/connectionState/*' => Http::response(['instance' => ['state' => 'connecting']], 200),
            '*instance/connect/*' => Http::response(['base64' => 'QR'], 200),
        ]);

        Livewire::test(Conexao::class)->call('gerarQr');

        // Nenhum canal novo; nenhum POST /instance/create (reprovisionamento).
        $this->assertSame(1, Channel::withoutAccountScope()->where('account_id', $a->id)->count());
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/instance/create'));
    }

    public function test_painel_qr_presente_na_conexao(): void
    {
        [$a, $c] = $this->contaComCanal();
        Http::fake([
            '*instance/connectionState/*' => Http::response(['instance' => ['state' => 'connecting']], 200),
            '*instance/connect/*' => Http::response(['base64' => 'QR'], 200),
        ]);

        Livewire::test(Conexao::class)
            ->assertSee('Atualizar QR')          // painel unico
            ->assertSee('Expira em')             // countdown
            ->assertSeeHtml('$wire.gerarQr()');  // auto-refresh do countdown
    }

    public function test_painel_qr_presente_no_conversas(): void
    {
        [$a, $c] = $this->contaComCanal();
        Http::fake([
            '*instance/connectionState/*' => Http::response(['instance' => ['state' => 'connecting']], 200),
            '*instance/connect/*' => Http::response(['base64' => 'QR'], 200),
        ]);

        Livewire::test(StatusConexao::class)
            ->call('abrirQr')                    // abre o modal de QR
            ->assertSet('showQr', true)
            ->assertSee('Atualizar QR')
            ->assertSeeHtml('$wire.abrirQr()');  // refresh scoped ao StatusConexao
    }
}
