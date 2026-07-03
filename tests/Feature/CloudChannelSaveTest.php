<?php

namespace Tests\Feature;

use App\Channels\CloudApi\SaveCloudChannel;
use App\Models\Account;
use App\Models\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Prompt 24a — caracterizacao do `msg:channel:create-cloud` (trava o comportamento
 * ANTES do refactor) + testes do Action SaveCloudChannel (depois da extracao).
 * Comportamentos ESTAVEIS aqui — devem passar antes E depois de delegar ao Action.
 */
class CloudChannelSaveTest extends TestCase
{
    use RefreshDatabase;

    private function conta(string $nome = 'Conta Cloud'): Account
    {
        return Account::create(['name' => $nome]);
    }

    private const Q_PHONE = 'phone_number_id (ID NUMERICO do numero no painel da Meta — nao e o telefone)';
    private const Q_WABA = 'waba_id (WhatsApp Business Account id, NUMERICO — nao e o app id)';
    private const Q_ACCESS = 'access_token (o TOKEN GRANDE da Meta, comeca com EAA... — oculto)';
    private const Q_APPSEC = 'app_secret (App settings > Basic, hex curto — NAO e o access token; oculto)';

    private function qVerify(bool $update = false): string
    {
        return 'verify_token (a string CURTA que VOCE inventou pro webhook — NAO o token EAA... da Meta; '
            . ($update ? 'vazio = mantem o atual; oculto)' : 'vazio = gero um; oculto)');
    }

    // ---- caracterizacao do comando (estavel) -----------------------------------

    public function test_caracterizacao_cria_canal_com_verify_informado_e_cifrado(): void
    {
        $a = $this->conta();

        $this->artisan('msg:channel:create-cloud', ['--account' => $a->id])
            ->expectsQuestion(self::Q_PHONE, '333444555666')
            ->expectsQuestion(self::Q_WABA, '888999000')
            ->expectsQuestion(self::Q_ACCESS, 'EAAtoken-grande')
            ->expectsQuestion(self::Q_APPSEC, 'appsecret-hex')
            ->expectsQuestion($this->qVerify(), 'meu-verify-curto')
            ->assertSuccessful();

        $canal = Channel::withoutAccountScope()->where('account_id', $a->id)->where('instance', '333444555666')->firstOrFail();
        $this->assertSame('cloud_api', $canal->provider);
        $this->assertSame('EAAtoken-grande', $canal->credentials['access_token']);
        $this->assertSame('meu-verify-curto', $canal->credentials['verify_token']);
        $this->assertSame('888999000', $canal->credentials['waba_id']);
        $this->assertNotNull($canal->webhook_token);
        // cifrado em repouso
        $cru = (string) DB::table('channels')->where('id', $canal->id)->value('credentials');
        $this->assertStringNotContainsString('EAAtoken-grande', $cru);
        $this->assertStringNotContainsString('appsecret-hex', $cru);
    }

    public function test_caracterizacao_verify_vazio_gera_um(): void
    {
        $a = $this->conta();

        $this->artisan('msg:channel:create-cloud', ['--account' => $a->id])
            ->expectsQuestion(self::Q_PHONE, '333444555666')
            ->expectsQuestion(self::Q_WABA, '888999000')
            ->expectsQuestion(self::Q_ACCESS, 'EAAtoken-grande')
            ->expectsQuestion(self::Q_APPSEC, 'appsecret-hex')
            ->expectsQuestion($this->qVerify(), '')
            ->assertSuccessful();

        $canal = Channel::withoutAccountScope()->where('instance', '333444555666')->firstOrFail();
        $this->assertSame(32, strlen((string) $canal->credentials['verify_token'])); // gerado
    }

    public function test_caracterizacao_update_preserva_webhook_token_e_instance(): void
    {
        $a = $this->conta();
        $canal = Channel::create([
            'account_id' => $a->id, 'instance' => '333444555666', 'provider' => 'cloud_api',
            'webhook_token' => 'TOKEN-FIXO-PRESERVA', 'status' => 'disconnected',
            'credentials' => ['access_token' => 'EAAvelho', 'phone_number_id' => '333444555666',
                'waba_id' => '111', 'app_secret' => 'sec-velho', 'verify_token' => 'verify-velho'],
        ]);

        $this->artisan('msg:channel:create-cloud', ['--account' => $a->id, '--update' => true])
            ->expectsQuestion(self::Q_PHONE, '333444555666')
            ->expectsQuestion(self::Q_WABA, '222')
            ->expectsQuestion(self::Q_ACCESS, 'EAAnovo')
            ->expectsQuestion(self::Q_APPSEC, 'sec-novo')
            ->expectsQuestion($this->qVerify(true), '') // vazio mantem o atual
            ->assertSuccessful();

        $fresh = $canal->fresh();
        $this->assertSame('TOKEN-FIXO-PRESERVA', $fresh->webhook_token); // preservado
        $this->assertSame('333444555666', $fresh->instance);             // preservado
        $this->assertSame('EAAnovo', $fresh->credentials['access_token']); // atualizado
        $this->assertSame('222', $fresh->credentials['waba_id']);
        $this->assertSame('verify-velho', $fresh->credentials['verify_token']); // mantido (vazio)
        $this->assertSame(1, Channel::withoutAccountScope()->where('account_id', $a->id)->count()); // sem duplicar
    }

    public function test_caracterizacao_phone_invalido_rejeita_sem_persistir(): void
    {
        $a = $this->conta();

        $this->artisan('msg:channel:create-cloud', ['--account' => $a->id])
            ->expectsQuestion(self::Q_PHONE, 'nao-e-numero')
            ->assertFailed();

        $this->assertSame(0, Channel::withoutAccountScope()->where('account_id', $a->id)->count());
    }

    // ---- Action SaveCloudChannel isolado ---------------------------------------

    private function input(array $over = []): array
    {
        return array_merge([
            'phone_number_id' => '333444555666', 'waba_id' => '888999000',
            'access_token' => 'EAAtoken-grande', 'app_secret' => 'appsecret-hex', 'verify_token' => 'verify-curto',
        ], $over);
    }

    public function test_action_cria_canal_com_credenciais_cifradas_e_escopado(): void
    {
        $a = $this->conta();
        $r = app(SaveCloudChannel::class)->handle($a, $this->input());

        $this->assertTrue($r->ok);
        $this->assertNull($r->error);
        $this->assertSame($a->id, $r->channel->account_id);
        $this->assertSame('cloud_api', $r->channel->provider);
        $this->assertNotNull($r->channel->webhook_token);
        // cifrado em repouso
        $cru = (string) DB::table('channels')->where('id', $r->channel->id)->value('credentials');
        $this->assertStringNotContainsString('EAAtoken-grande', $cru);
        $this->assertStringNotContainsString('appsecret-hex', $cru);
    }

    public function test_action_update_preserva_webhook_token_e_instance(): void
    {
        $a = $this->conta();
        $canal = Channel::create([
            'account_id' => $a->id, 'instance' => '333444555666', 'provider' => 'cloud_api',
            'webhook_token' => 'TOK-PRESERVA', 'status' => 'disconnected',
            'credentials' => ['access_token' => 'EAAvelho', 'phone_number_id' => '333444555666',
                'waba_id' => '111', 'app_secret' => 'sec-velho', 'verify_token' => 'verify-velho'],
        ]);

        $r = app(SaveCloudChannel::class)->handle($a, $this->input(['access_token' => 'EAAnovo', 'verify_token' => '']), update: true);

        $this->assertTrue($r->ok);
        $this->assertFalse($r->verifyGerado);
        $fresh = $canal->fresh();
        $this->assertSame('TOK-PRESERVA', $fresh->webhook_token);         // preservado
        $this->assertSame('333444555666', $fresh->instance);              // preservado
        $this->assertSame('EAAnovo', $fresh->credentials['access_token']); // atualizado
        $this->assertSame('verify-velho', $fresh->credentials['verify_token']); // vazio mantem
        $this->assertSame(1, Channel::withoutAccountScope()->where('account_id', $a->id)->count());
    }

    public function test_action_anti_swap_verify_eaa_e_erro_sem_io(): void
    {
        $a = $this->conta();
        $r = app(SaveCloudChannel::class)->handle($a, $this->input(['verify_token' => 'EAAcolou-token-errado']));

        $this->assertFalse($r->ok);
        $this->assertStringContainsString('EAA', (string) $r->error);
        $this->assertSame(0, Channel::withoutAccountScope()->where('account_id', $a->id)->count()); // nada persistido
    }

    public function test_action_access_token_sem_eaa_gera_aviso_nao_bloqueante(): void
    {
        $a = $this->conta();
        $r = app(SaveCloudChannel::class)->handle($a, $this->input(['access_token' => 'sem-prefixo-eaa']));

        $this->assertTrue($r->ok); // NAO bloqueia
        $this->assertNotNull($r->warning);
        $this->assertStringContainsString('EAA', $r->warning);
        $this->assertNotNull(Channel::withoutAccountScope()->where('account_id', $a->id)->first());
    }

    public function test_action_verify_vazio_gera_token(): void
    {
        $a = $this->conta();
        $r = app(SaveCloudChannel::class)->handle($a, $this->input(['verify_token' => '']));

        $this->assertTrue($r->ok);
        $this->assertTrue($r->verifyGerado);
        $this->assertSame(32, strlen((string) $r->channel->credentials['verify_token']));
    }

    public function test_action_isolamento_salvar_em_a_nao_afeta_b(): void
    {
        $a = $this->conta('Conta A');
        $b = $this->conta('Conta B');

        app(SaveCloudChannel::class)->handle($a, $this->input(['phone_number_id' => '111222333']));

        $this->assertSame(1, Channel::withoutAccountScope()->where('account_id', $a->id)->count());
        $this->assertSame(0, Channel::withoutAccountScope()->where('account_id', $b->id)->count());
    }
}
