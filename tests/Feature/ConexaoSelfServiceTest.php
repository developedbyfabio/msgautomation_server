<?php

namespace Tests\Feature;

use App\Livewire\Conexao;
use App\Models\Account;
use App\Models\Channel;
use App\Tenancy\AccountContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 23 — conexao de canal self-service (Evolution). Conta SEM canal clica
 * "Conectar" -> ChannelProvisioner cria instancia/token/webhook DA CONTA ATIVA e
 * ja mostra o QR. Idempotente (com canal, nao recria) e escopado por conta.
 * Evolution HTTP sempre MOCKADO.
 */
class ConexaoSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.evolution.base_url' => 'http://evo-test:8090',
            'services.evolution.api_key' => 'chave-evo',
            'services.evolution.webhook_url' => 'http://app.local/webhook/evolution',
        ]);
        // Evolution: instancia nao existe -> cria; webhook ausente -> configura;
        // estado connecting -> mostra QR (nao redireciona pra conversas).
        Http::fake([
            'evo-test:8090/instance/fetchInstances' => Http::response([], 200),
            'evo-test:8090/instance/create' => Http::response(['instance' => ['instanceName' => 'x']], 201),
            'evo-test:8090/webhook/find/*' => Http::response([], 200),
            'evo-test:8090/webhook/set/*' => Http::response(['ok' => true], 200),
            'evo-test:8090/instance/connectionState/*' => Http::response(['instance' => ['state' => 'connecting']], 200),
            'evo-test:8090/instance/connect/*' => Http::response(['base64' => 'data:image/png;base64,QRCODE'], 200),
        ]);
    }

    private function conta(string $nome): Account
    {
        $a = Account::create(['name' => $nome]);
        app(AccountContext::class)->set($a->id);

        return $a;
    }

    public function test_conta_sem_canal_conecta_provisiona_e_mostra_qr(): void
    {
        $a = $this->conta('Cliente Novo');
        $this->assertNull(Channel::defaultFor($a->id)); // comeca sem canal

        Livewire::test(Conexao::class)
            ->assertSet('temCanal', false)
            ->call('conectar')
            ->assertSet('temCanal', true)
            ->assertSet('qr', 'data:image/png;base64,QRCODE');

        // Canal da CONTA criado: instancia conta-{id}-slug, token e provider evolution.
        $canal = Channel::defaultFor($a->id);
        $this->assertNotNull($canal);
        $this->assertSame('evolution', $canal->provider);
        $this->assertStringStartsWith('conta-' . $a->id . '-', $canal->instance);
        $this->assertNotNull($canal->webhook_token);
    }

    public function test_conta_com_canal_nao_recria_instancia(): void
    {
        $a = $this->conta('Ja Tem Canal');
        $existente = Channel::create([
            'account_id' => $a->id, 'instance' => 'conta-' . $a->id . '-existente',
            'provider' => 'evolution', 'webhook_token' => 'tok-fixo', 'status' => 'disconnected',
        ]);

        Livewire::test(Conexao::class)
            ->assertSet('temCanal', true)
            ->call('conectar'); // idempotente: nao cria outro

        $canais = Channel::withoutAccountScope()->where('account_id', $a->id)->get();
        $this->assertCount(1, $canais); // nenhum duplicado
        $this->assertSame($existente->id, $canais->first()->id);
        $this->assertSame('tok-fixo', $canais->first()->webhook_token); // token intacto
    }

    public function test_provisionamento_escopado_a_conta_ativa_nao_toca_outra_conta(): void
    {
        $b = Account::create(['name' => 'Conta B']); // outra conta, sem canal
        $a = $this->conta('Conta A');                // contexto ativo = A

        Livewire::test(Conexao::class)->call('conectar');

        // Canal criado SO na conta A; B continua sem canal.
        $this->assertNotNull(Channel::defaultFor($a->id));
        $this->assertNull(Channel::defaultFor($b->id));
        $this->assertSame(1, Channel::withoutAccountScope()->where('account_id', $a->id)->count());
        $this->assertSame(0, Channel::withoutAccountScope()->where('account_id', $b->id)->count());
    }
}
