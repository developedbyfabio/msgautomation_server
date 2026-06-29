<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Secret;
use App\Whatsapp\Secrets\SecretMissingException;
use App\Whatsapp\Secrets\SecretVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * S1 — cofre cifrado. Round-trip + valor nao fica em claro no banco + resolucao
 * e redacao de {senha:nome}.
 */
class SecretVaultTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;
    private SecretVault $vault;

    protected function setUp(): void
    {
        parent::setUp();
        // Chave dedicada de teste (base64 de 32 bytes) — nao e a de producao.
        config(['secrets.key' => 'base64:' . base64_encode(str_repeat('k', 32)), 'secrets.cipher' => 'AES-256-CBC']);
        $this->account = Account::create(['name' => 'Teste']);
        $this->vault = app(SecretVault::class);
    }

    public function test_cifra_round_trip_e_valor_nao_fica_em_claro(): void
    {
        $this->vault->put($this->account->id, 'wifi_pais', 'SenhaSuperSecreta123');

        // No banco, o valor esta CIFRADO (nao contem o plaintext).
        $raw = DB::table('secrets')->where('nome', 'wifi_pais')->value('value_encrypted');
        $this->assertNotSame('SenhaSuperSecreta123', $raw);
        $this->assertStringNotContainsString('SenhaSuperSecreta123', $raw);

        // Decifra de volta corretamente.
        $this->assertSame('SenhaSuperSecreta123', $this->vault->reveal($this->account->id, 'wifi_pais'));
    }

    public function test_put_e_idempotente_por_nome(): void
    {
        $this->vault->put($this->account->id, 'wifi', 'um');
        $this->vault->put($this->account->id, 'wifi', 'dois');

        $this->assertSame(1, Secret::where('account_id', $this->account->id)->where('nome', 'wifi')->count());
        $this->assertSame('dois', $this->vault->reveal($this->account->id, 'wifi'));
    }

    public function test_resolve_substitui_em_memoria(): void
    {
        $this->vault->put($this->account->id, 'wifi', 'abc123');

        $out = $this->vault->resolve($this->account->id, 'A senha do wifi e {senha:wifi}, ok?');
        $this->assertSame('A senha do wifi e abc123, ok?', $out);
    }

    public function test_resolve_senha_ausente_lanca_excecao_redigida(): void
    {
        try {
            $this->vault->resolve($this->account->id, 'segue {senha:inexistente}');
            $this->fail('deveria lancar');
        } catch (SecretMissingException $e) {
            $this->assertStringContainsString('inexistente', $e->getMessage());
            $this->assertSame('inexistente', $e->nome);
        }
    }

    public function test_redact_e_mask_nao_expoem_valor(): void
    {
        $this->vault->put($this->account->id, 'wifi', 'abc123');

        $this->assertSame('senha: [senha: wifi]', $this->vault->redact('senha: {senha:wifi}'));
        $this->assertStringNotContainsString('abc123', $this->vault->redact('{senha:wifi}'));
        $this->assertStringContainsString('••••', $this->vault->mask('{senha:wifi}'));
    }

    public function test_names_lista_nomes_nao_valores(): void
    {
        $this->vault->put($this->account->id, 'wifi', 'abc');
        $this->vault->put($this->account->id, 'cofre', 'xyz');

        $names = $this->vault->names($this->account->id);
        $this->assertEqualsCanonicalizing(['wifi', 'cofre'], $names);
    }
}
