<?php

namespace Tests\Feature;

use App\Channels\Evolution\EvolutionProvider;
use App\Models\Account;
use App\Models\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 27, Fatia 3 — a INSTANCIA nunca cai no fallback global do .env.
 * base_url/apikey seguem do env (infra compartilhada); instancia = SEMPRE do canal.
 */
class CredenciaisSemFallbackGlobalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.evolution.base_url' => 'http://evo-test:8090',
            'services.evolution.api_key' => 'chave-infra',
            'services.evolution.instance' => 'fabio-pessoal', // instancia GLOBAL do .env
        ]);
    }

    public function test_canal_null_nao_retorna_instancia_global(): void
    {
        $cred = app(EvolutionProvider::class)->credentialsFor(null);

        $this->assertNotSame('fabio-pessoal', $cred['instance']); // NUNCA a global
        $this->assertSame('', $cred['instance']);                 // vazia = no-op
        // base_url/apikey seguem como infra compartilhada
        $this->assertSame('http://evo-test:8090', $cred['base_url']);
        $this->assertSame('chave-infra', $cred['apikey']);
    }

    public function test_canal_existente_usa_a_propria_instancia(): void
    {
        $a = Account::create(['name' => 'Fabio']);
        $canal = Channel::create([
            'account_id' => $a->id, 'instance' => 'fabio-pessoal', 'provider' => 'evolution',
            'webhook_token' => 'tok', 'status' => 'connected',
            'credentials' => ['base_url' => 'http://evo-test:8090', 'apikey' => 'k', 'instance' => 'fabio-pessoal'],
        ]);

        $cred = app(EvolutionProvider::class)->credentialsFor($canal);
        $this->assertSame('fabio-pessoal', $cred['instance']); // do CANAL, nao do fallback
    }

    public function test_canal_sem_credentials_usa_instance_da_coluna(): void
    {
        // Canal existente mas com credentials vazias: usa $channel->instance (nao a global).
        $a = Account::create(['name' => 'Conta X']);
        $canal = Channel::create([
            'account_id' => $a->id, 'instance' => 'conta-x-inst', 'provider' => 'evolution',
            'webhook_token' => 'tok', 'status' => 'disconnected',
        ]);

        $cred = app(EvolutionProvider::class)->credentialsFor($canal);
        $this->assertSame('conta-x-inst', $cred['instance']);
        $this->assertNotSame('fabio-pessoal', $cred['instance']);
    }
}
