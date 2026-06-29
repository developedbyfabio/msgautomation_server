<?php

namespace Tests\Feature;

use App\Livewire\Senhas;
use App\Models\Account;
use App\Models\Secret;
use App\Models\User;
use App\Whatsapp\Secrets\SecretVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * S2 — aba Senhas (CRUD + revelar deliberado). Valor cifrado; nunca exibido em massa.
 */
class SenhasTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'Teste']);
    }

    public function test_cria_senha_cifrada(): void
    {
        Livewire::test(Senhas::class)
            ->call('novo')
            ->set('nome', 'wifi_pais')
            ->set('valor', 'MinhaSenha#1')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $this->assertDatabaseHas('secrets', ['nome' => 'wifi_pais', 'account_id' => $this->account->id]);
        // Valor cifrado no banco.
        $raw = DB::table('secrets')->where('nome', 'wifi_pais')->value('value_encrypted');
        $this->assertStringNotContainsString('MinhaSenha#1', $raw);
        // Decifra de volta.
        $this->assertSame('MinhaSenha#1', app(SecretVault::class)->reveal($this->account->id, 'wifi_pais'));
    }

    public function test_lista_nao_mostra_valor(): void
    {
        app(SecretVault::class)->put($this->account->id, 'wifi', 'SuperSecreta');

        Livewire::test(Senhas::class)
            ->assertSee('wifi')
            ->assertDontSee('SuperSecreta'); // mascarado por padrao
    }

    public function test_editar_metadados_sem_valor_mantem_o_valor(): void
    {
        app(SecretVault::class)->put($this->account->id, 'wifi', 'Original123');
        $id = Secret::where('nome', 'wifi')->value('id');

        Livewire::test(Senhas::class)
            ->call('edit', $id)
            ->assertSet('valor', '') // nao carrega o valor no form
            ->set('categoria', 'rede')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('secrets', ['id' => $id, 'categoria' => 'rede']);
        $this->assertSame('Original123', app(SecretVault::class)->reveal($this->account->id, 'wifi'));
    }

    public function test_revelar_exige_senha_de_login_correta(): void
    {
        app(SecretVault::class)->put($this->account->id, 'wifi', 'ValorRevelado9');
        $id = Secret::where('nome', 'wifi')->value('id');
        $this->actingAs(User::create(['name' => 'Op', 'email' => 'op@x.local', 'password' => Hash::make('login-correta')]));

        // Senha errada -> nao revela.
        Livewire::test(Senhas::class)
            ->call('askReveal', $id)
            ->set('revealPassword', 'errada')
            ->call('confirmReveal')
            ->assertHasErrors('revealPassword')
            ->assertSet('revealedValue', null);

        // Senha certa -> revela (transiente).
        Livewire::test(Senhas::class)
            ->call('askReveal', $id)
            ->set('revealPassword', 'login-correta')
            ->call('confirmReveal')
            ->assertSet('revealedId', $id)
            ->assertSet('revealedValue', 'ValorRevelado9');
    }

    public function test_excluir_confirma_e_apaga(): void
    {
        app(SecretVault::class)->put($this->account->id, 'wifi', 'x');
        $id = Secret::where('nome', 'wifi')->value('id');

        $c = Livewire::test(Senhas::class)->call('confirmDelete', $id);
        $this->assertDatabaseHas('secrets', ['id' => $id]);
        $c->call('deleteConfirmed');
        $this->assertDatabaseMissing('secrets', ['id' => $id]);
    }
}
