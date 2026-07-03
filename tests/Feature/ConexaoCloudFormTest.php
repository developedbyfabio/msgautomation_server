<?php

namespace Tests\Feature;

use App\Livewire\Conexao;
use App\Models\Account;
use App\Models\Channel;
use App\Tenancy\AccountContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 24b — form de credenciais Cloud na /conexao (consome SaveCloudChannel),
 * escopado a conta ativa. Segredos cifrados e nunca exibidos em texto; webhook URL
 * pela base da config. Evolution HTTP mockado (o poll do mount nao chama rede).
 */
class ConexaoCloudFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.cloud_api.webhook_base' => 'https://wa.nextgest.com.br']);
        Http::fake(['*' => Http::response([], 200)]); // nenhum HTTP real no poll
    }

    private function conta(string $nome = 'Conta Cloud'): Account
    {
        $a = Account::create(['name' => $nome]);
        app(AccountContext::class)->set($a->id);

        return $a;
    }

    public function test_form_cria_canal_cloud_cifrado_e_escopado(): void
    {
        $a = $this->conta();

        Livewire::test(Conexao::class)
            ->call('abrirCloud')
            ->set('cloudPhone', '333444555666')->set('cloudWaba', '888999000')
            ->set('cloudAccessToken', 'EAAtoken-grande')->set('cloudAppSecret', 'appsecret-hex')
            ->set('cloudVerify', 'meu-verify')
            ->call('salvarCloud')
            ->assertSet('cloudSalvo', true)
            ->assertSet('cloudError', null);

        $canal = Channel::withoutAccountScope()->where('account_id', $a->id)->where('provider', 'cloud_api')->firstOrFail();
        $this->assertSame('333444555666', $canal->instance);
        $this->assertSame('EAAtoken-grande', $canal->credentials['access_token']);
        // cifrado em repouso
        $cru = (string) DB::table('channels')->where('id', $canal->id)->value('credentials');
        $this->assertStringNotContainsString('EAAtoken-grande', $cru);
        $this->assertStringNotContainsString('appsecret-hex', $cru);
    }

    public function test_anti_swap_verify_eaa_mostra_erro_e_nao_persiste(): void
    {
        $a = $this->conta();

        Livewire::test(Conexao::class)
            ->call('abrirCloud')
            ->set('cloudPhone', '333444555666')->set('cloudWaba', '888')
            ->set('cloudAccessToken', 'EAAtoken')->set('cloudAppSecret', 'sec')
            ->set('cloudVerify', 'EAAcolou-errado')
            ->call('salvarCloud')
            ->assertSet('cloudSalvo', false)
            ->assertSet('cloudError', fn ($e) => is_string($e) && str_contains($e, 'EAA'));

        $this->assertSame(0, Channel::withoutAccountScope()->where('account_id', $a->id)->count());
    }

    public function test_update_atualiza_sem_duplicar(): void
    {
        $a = $this->conta();
        $canal = Channel::create([
            'account_id' => $a->id, 'instance' => '333444555666', 'provider' => 'cloud_api',
            'webhook_token' => 'TOK-FIXO', 'status' => 'disconnected',
            'credentials' => ['access_token' => 'EAAvelho', 'phone_number_id' => '333444555666',
                'waba_id' => '111', 'app_secret' => 'sec-velho', 'verify_token' => 'verify-velho'],
        ]);

        Livewire::test(Conexao::class)
            ->call('abrirCloud')
            ->assertSet('cloudPhone', '333444555666') // pre-preenche nao-segredo
            ->set('cloudAccessToken', 'EAAnovo')->set('cloudAppSecret', 'sec-novo')->set('cloudVerify', '')
            ->call('salvarCloud')
            ->assertSet('cloudSalvo', true);

        $fresh = $canal->fresh();
        $this->assertSame('TOK-FIXO', $fresh->webhook_token); // preservado
        $this->assertSame('EAAnovo', $fresh->credentials['access_token']);
        $this->assertSame(1, Channel::withoutAccountScope()->where('account_id', $a->id)->count());
    }

    public function test_seguranca_access_token_nao_fica_no_estado_apos_salvar(): void
    {
        $this->conta();

        Livewire::test(Conexao::class)
            ->call('abrirCloud')
            ->set('cloudPhone', '333444555666')->set('cloudWaba', '888')
            ->set('cloudAccessToken', 'EAAsupersecreto')->set('cloudAppSecret', 'appsec')
            ->set('cloudVerify', 'v')
            ->call('salvarCloud')
            // segredos resetados do componente; token so mascarado (ultimos 4).
            ->assertSet('cloudAccessToken', '')
            ->assertSet('cloudAppSecret', '')
            ->assertSet('cloudTokenMasked', fn ($m) => is_string($m) && ! str_contains($m, 'EAAsupersecreto') && str_contains($m, 'reto'));
    }

    public function test_webhook_url_usa_base_da_config_e_token_da_conta(): void
    {
        $this->conta();

        $c = Livewire::test(Conexao::class)
            ->call('abrirCloud')
            ->set('cloudPhone', '333444555666')->set('cloudWaba', '888')
            ->set('cloudAccessToken', 'EAAtok')->set('cloudAppSecret', 'sec')->set('cloudVerify', 'meuverify')
            ->call('salvarCloud');

        $canal = Channel::withoutAccountScope()->where('instance', '333444555666')->firstOrFail();
        $c->assertSet('cloudCallbackUrl', 'https://wa.nextgest.com.br/webhook/cloud/' . $canal->webhook_token);
        // helper do model == comando (fonte unica)
        $this->assertSame($canal->cloudCallbackUrl(), 'https://wa.nextgest.com.br/webhook/cloud/' . $canal->webhook_token);
    }

    public function test_isolamento_form_so_ve_e_cria_na_conta_ativa(): void
    {
        $b = Account::create(['name' => 'Conta B']);
        Channel::create([
            'account_id' => $b->id, 'instance' => '999888777', 'provider' => 'cloud_api',
            'webhook_token' => 'TOK-B', 'status' => 'disconnected',
            'credentials' => ['access_token' => 'EAAb', 'phone_number_id' => '999888777', 'waba_id' => 'b', 'app_secret' => 's', 'verify_token' => 'vb'],
        ]);
        $a = $this->conta('Conta A'); // contexto ativo = A

        // abrirCloud NAO pre-preenche com dados da B (conta A nao tem canal cloud).
        Livewire::test(Conexao::class)
            ->call('abrirCloud')
            ->assertSet('cloudPhone', '')
            ->set('cloudPhone', '111222333')->set('cloudWaba', 'a')
            ->set('cloudAccessToken', 'EAAa')->set('cloudAppSecret', 'sa')->set('cloudVerify', 'va')
            ->call('salvarCloud')->assertSet('cloudSalvo', true);

        // Canal criado so em A; B intacto.
        $this->assertSame(1, Channel::withoutAccountScope()->where('account_id', $a->id)->count());
        $this->assertSame('EAAb', Channel::withoutAccountScope()->where('account_id', $b->id)->firstOrFail()->credentials['access_token']);
    }
}
