<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Prompt 27, Fatia 2 — gate whatsapp.connected: conta SEM canal vai pra /conexao
 * (conectar o proprio WhatsApp); conta COM canal conectado passa. /conexao e telas
 * fora do gate nao entram em loop de redirect.
 */
class GateSemCanalTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioDe(Account $a): User
    {
        $u = User::create(['name' => 'Op', 'email' => 'op' . $a->id . '@x.local', 'password' => Hash::make('senha-forte-123')]);
        $u->accounts()->attach($a->id, ['role' => 'owner']);
        $this->actingAs($u)->withSession(['tenancy.account_id' => $a->id]);

        return $u;
    }

    public function test_conta_sem_canal_redireciona_para_conexao(): void
    {
        $a = Account::create(['name' => 'Sem Canal']);
        $this->usuarioDe($a);

        $this->get('/conversas')->assertRedirect(route('conexao'));
        $this->get('/painel')->assertRedirect(route('conexao'));
    }

    public function test_conta_com_canal_conectado_passa(): void
    {
        $a = Account::create(['name' => 'Com Canal']);
        Channel::create([
            'account_id' => $a->id, 'instance' => 'conta-' . $a->id . '-x', 'provider' => 'evolution',
            'webhook_token' => 'tok', 'status' => 'connected',
        ]);
        $this->usuarioDe($a);

        $this->get('/conversas')->assertOk();
    }

    public function test_canal_desconectado_tambem_vai_pra_conexao(): void
    {
        $a = Account::create(['name' => 'Desconectado']);
        Channel::create([
            'account_id' => $a->id, 'instance' => 'conta-' . $a->id . '-x', 'provider' => 'evolution',
            'webhook_token' => 'tok', 'status' => 'disconnected',
        ]);
        $this->usuarioDe($a);

        $this->get('/conversas')->assertRedirect(route('conexao'));
    }

    public function test_conexao_e_telas_sem_gate_nao_entram_em_loop(): void
    {
        $a = Account::create(['name' => 'Sem Canal']);
        $this->usuarioDe($a);

        // /conexao e /perfil estao FORA do grupo whatsapp.connected -> sem redirect.
        $this->get(route('conexao'))->assertOk();
        $this->get(route('perfil'))->assertOk();
    }
}
