<?php

namespace Tests\Feature;

use App\Livewire\Senhas;
use App\Models\Account;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\Secret;
use App\Models\User;
use App\Tenancy\AccountContext;
use App\Whatsapp\Secrets\SecretVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fatia 24 — "Senhas" vira "Cofre de credenciais" SO NA FACHADA (menu, h1,
 * copy). Identificadores byte-identicos: token {senha:nome} (contrato de dados
 * em producao), rota/name 'senhas', chave do AreaAccess::MAP, componente,
 * inserirSenhaNo, confirmReveal, schema. O acesso owner-only e a guarda
 * anti-exfiltracao JA foram entregues (fatias 22/15) — aqui so REAFIRMADOS
 * (os testes originais deles passam sem nenhuma alteracao: RolePermissionsTest,
 * SecretSendTest, KbVariableTest).
 */
class CofreFachadaTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;
    private User $owner;
    private User $operador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'A']);
        Channel::create(['account_id' => $this->account->id, 'instance' => 'inst-a', 'provider' => 'evolution', 'webhook_token' => 'tok-a', 'status' => 'connected']);
        AutoReplySetting::create(['account_id' => $this->account->id]);

        $this->owner = User::create(['name' => 'Dono', 'email' => 'dono@x.local', 'password' => Hash::make('senha-forte-123')]);
        $this->owner->accounts()->attach($this->account->id, ['role' => 'owner']);
        $this->operador = User::create(['name' => 'Sec', 'email' => 'sec@x.local', 'password' => Hash::make('senha-forte-123')]);
        $this->operador->accounts()->attach($this->account->id, ['role' => 'operador']);
    }

    public function test_fachada_renomeada_menu_h1_e_copy(): void
    {
        $resp = $this->actingAs($this->owner)->withSession(['tenancy.account_id' => $this->account->id])
            ->get(route('senhas'))->assertOk(); // ROTA/name intocados

        $resp->assertSee('Cofre de credenciais');           // h1 + menu novos
        $resp->assertSee('Nova credencial');
        $resp->assertDontSee('Senhas (cofre)');             // rotulos ANTIGOS sumiram
        $resp->assertDontSee('Nova senha');
        // O TOKEN segue documentado byte-identico na copy (contrato de dados).
        $resp->assertSee('{senha:nome}');
    }

    public function test_url_e_rota_intocadas(): void
    {
        $this->assertSame(url('/senhas'), route('senhas'));
        $this->assertSame('owner', \App\Auth\AreaAccess::MAP['senhas']); // chave do mapa identica
    }

    // ---- Reafirmacoes (fatia 22 — nao reimplementado) ----------------------------

    public function test_reafirma_operador_segue_barrado_na_rota_e_no_confirm_reveal(): void
    {
        $this->actingAs($this->operador)->withSession(['tenancy.account_id' => $this->account->id])
            ->get(route('senhas'))->assertForbidden();

        app(AccountContext::class)->set($this->account->id);
        app(SecretVault::class)->put($this->account->id, 'wifi', 'ValorSecreto9');
        $id = Secret::withoutAccountScope()->where('nome', 'wifi')->value('id');

        $this->actingAs($this->operador);
        Livewire::test(Senhas::class)
            ->call('askReveal', $id)
            ->set('revealPassword', 'senha-forte-123')
            ->call('confirmReveal')
            ->assertForbidden()
            ->assertSet('revealedValue', null); // gate intacto pos-renomeacao
    }

    public function test_reafirma_owner_revela_com_reautenticacao(): void
    {
        app(AccountContext::class)->set($this->account->id);
        app(SecretVault::class)->put($this->account->id, 'wifi', 'ValorSecreto9');
        $id = Secret::withoutAccountScope()->where('nome', 'wifi')->value('id');

        $this->actingAs($this->owner);
        Livewire::test(Senhas::class)
            ->call('askReveal', $id)
            ->set('revealPassword', 'senha-forte-123')
            ->call('confirmReveal')
            ->assertSet('revealedValue', 'ValorSecreto9'); // fluxo do owner intacto
    }
}
