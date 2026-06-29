<?php

namespace Tests\Feature;

use App\Livewire\Conexao;
use App\Livewire\StatusConexao;
use App\Models\Account;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * S3 — conexao/QR e desconectar. TUDO com HTTP mockado: a sessao real NUNCA
 * e desconectada nem o QR real e gerado nos testes.
 */
class ConexaoTest extends TestCase
{
    use RefreshDatabase;

    private function instancia(): string
    {
        return (string) config('services.evolution.instance');
    }

    private function canal(string $status = 'connected'): Channel
    {
        $account = Account::create(['name' => 'Teste']);

        return Channel::create([
            'account_id' => $account->id,
            'instance' => $this->instancia(),
            'status' => $status,
        ]);
    }

    // ---- Tela de conexao (QR) ----------------------------------------------

    public function test_conexao_redireciona_quando_ja_open(): void
    {
        Http::fake(['*/instance/connectionState/*' => Http::response(['instance' => ['state' => 'open']], 200)]);
        $this->canal('disconnected');

        Livewire::test(Conexao::class)
            ->assertRedirect(route('conversas'));
    }

    public function test_conexao_mostra_qr_quando_desconectado(): void
    {
        Http::fake([
            '*/instance/connectionState/*' => Http::response(['instance' => ['state' => 'close']], 200),
            '*/instance/connect/*' => Http::response(['base64' => 'AAAAQRDATA'], 200),
        ]);
        $this->canal('disconnected');

        Livewire::test(Conexao::class)
            ->assertSet('state', 'close')
            ->assertSet('qr', 'data:image/png;base64,AAAAQRDATA')
            ->assertSee('Conectar o WhatsApp');
    }

    public function test_conexao_gerar_novo_qr(): void
    {
        Http::fake([
            '*/instance/connectionState/*' => Http::response(['instance' => ['state' => 'close']], 200),
            '*/instance/connect/*' => Http::response(['base64' => 'NOVOQR'], 200),
        ]);
        $this->canal('disconnected');

        Livewire::test(Conexao::class)
            ->call('gerarQr')
            ->assertSet('qr', 'data:image/png;base64,NOVOQR');
    }

    // ---- Desconectar (logout) ----------------------------------------------

    public function test_desconectar_confirma_e_chama_logout(): void
    {
        Http::fake(['*/instance/logout/*' => Http::response(['status' => 'SUCCESS'], 200)]);
        $this->canal('connected');

        Livewire::test(StatusConexao::class)
            ->call('confirmDisconnect')
            ->assertSet('confirmingDisconnect', true)
            ->call('disconnectConfirmed')
            ->assertSet('state', 'close')
            ->assertRedirect(route('conexao'));

        Http::assertSent(fn ($r) => str_contains($r->url(), '/instance/logout/') && $r->method() === 'DELETE');
        $this->assertDatabaseHas('channels', ['instance' => $this->instancia(), 'status' => 'disconnected']);
    }

    public function test_desconectar_cancelar_nao_chama_logout(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->canal('connected');

        Livewire::test(StatusConexao::class)
            ->call('confirmDisconnect')
            ->call('cancelDisconnect')
            ->assertSet('confirmingDisconnect', false);

        Http::assertNothingSent();
        $this->assertDatabaseHas('channels', ['instance' => $this->instancia(), 'status' => 'connected']);
    }

    public function test_desconectar_falha_http_nao_redireciona(): void
    {
        Http::fake(['*/instance/logout/*' => Http::response(['error' => 'x'], 500)]);
        $this->canal('connected');

        Livewire::test(StatusConexao::class)
            ->call('disconnectConfirmed')
            ->assertNoRedirect();

        // Falha no logout NAO derruba o status local.
        $this->assertDatabaseHas('channels', ['instance' => $this->instancia(), 'status' => 'connected']);
    }

    // ---- Gate de conexao (middleware) --------------------------------------

    public function test_gate_redireciona_para_conexao_quando_desconectado(): void
    {
        $this->actingAs(User::factory()->create());
        $this->canal('disconnected');

        $this->get('/conversas')->assertRedirect(route('conexao'));
    }

    public function test_gate_passa_quando_conectado(): void
    {
        $this->actingAs(User::factory()->create());
        $this->canal('connected');

        $this->get('/conversas')->assertOk();
    }

    public function test_status_nao_rebaixa_em_estado_desconhecido(): void
    {
        // Evolution inacessivel -> state 'desconhecido' -> NAO mexe no status.
        Http::fake(['*/instance/connectionState/*' => Http::response([], 500)]);
        $this->canal('connected');

        Livewire::test(StatusConexao::class)->call('refresh');

        $this->assertDatabaseHas('channels', ['instance' => $this->instancia(), 'status' => 'connected']);
    }
}
