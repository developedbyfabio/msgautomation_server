<?php

namespace Tests\Feature;

use App\Livewire\Conhecimento;
use App\Models\Account;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Knowledge;
use App\Whatsapp\AutoReply\RuleTester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Camada 3 Fatia 2 — UI da base de conhecimento (/conhecimento) + linha da base no
 * testador (dry-run, sem chamar a API). Testes de componente Livewire.
 */
class ConhecimentoUiTest extends TestCase
{
    use RefreshDatabase;

    private const JID = '5541999990000@s.whatsapp.net';

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'T']);
    }

    public function test_criar_entrada(): void
    {
        Livewire::test(Conhecimento::class)
            ->call('novo')
            ->set('title', 'Horario')
            ->set('content', 'Atendemos das 8h as 18h.')
            ->set('sensitivity', 'low')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('knowledge', [
            'account_id' => $this->account->id, 'title' => 'Horario',
            'sensitivity' => 'low', 'active' => true,
        ]);
    }

    public function test_validacao_exige_titulo_conteudo_e_sensibilidade_valida(): void
    {
        Livewire::test(Conhecimento::class)
            ->call('novo')
            ->set('title', '')
            ->set('content', '')
            ->call('save')
            ->assertHasErrors(['title', 'content']);

        Livewire::test(Conhecimento::class)
            ->call('novo')
            ->set('title', 'X')
            ->set('content', 'Y')
            ->set('sensitivity', 'invalida')
            ->call('save')
            ->assertHasErrors(['sensitivity']);
    }

    public function test_editar_entrada_carrega_e_salva_permissoes(): void
    {
        $c = Contact::create(['account_id' => $this->account->id, 'remote_jid' => self::JID]);
        $k = Knowledge::create([
            'account_id' => $this->account->id, 'title' => 'Wifi',
            'content' => 'A senha e {senha:wifi}', 'sensitivity' => 'medium', 'active' => true,
        ]);

        Livewire::test(Conhecimento::class)
            ->call('edit', $k->id)
            ->assertSet('title', 'Wifi')
            ->assertSet('sensitivity', 'medium')
            ->set('sensitivity', 'high')
            ->set('contactIds', [$c->id])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('knowledge', ['id' => $k->id, 'sensitivity' => 'high']);
        $this->assertDatabaseHas('knowledge_contacts', ['knowledge_id' => $k->id, 'contact_id' => $c->id]);
    }

    public function test_contato_de_outro_account_nao_entra_na_permissao(): void
    {
        $outroAccount = Account::create(['name' => 'Outro']);
        $intruso = Contact::create(['account_id' => $outroAccount->id, 'remote_jid' => '555@s.whatsapp.net']);

        Livewire::test(Conhecimento::class)
            ->call('novo')
            ->set('title', 'X')
            ->set('content', 'Y')
            ->set('contactIds', [$intruso->id])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('knowledge_contacts', 0);
    }

    public function test_toggle_desativa_e_reativa(): void
    {
        $k = Knowledge::create([
            'account_id' => $this->account->id, 'title' => 'X', 'content' => 'Y',
            'sensitivity' => 'low', 'active' => true,
        ]);

        Livewire::test(Conhecimento::class)->call('toggle', $k->id);
        $this->assertDatabaseHas('knowledge', ['id' => $k->id, 'active' => false]);

        Livewire::test(Conhecimento::class)->call('toggle', $k->id);
        $this->assertDatabaseHas('knowledge', ['id' => $k->id, 'active' => true]);
    }

    public function test_excluir_exige_confirmacao(): void
    {
        $k = Knowledge::create([
            'account_id' => $this->account->id, 'title' => 'X', 'content' => 'Y',
            'sensitivity' => 'low', 'active' => true,
        ]);

        // Cancelar nao exclui.
        Livewire::test(Conhecimento::class)
            ->call('confirmDelete', $k->id)
            ->call('cancelDelete');
        $this->assertDatabaseHas('knowledge', ['id' => $k->id]);

        // Confirmar exclui (pivo cai por cascade).
        Livewire::test(Conhecimento::class)
            ->call('confirmDelete', $k->id)
            ->call('deleteConfirmed');
        $this->assertDatabaseMissing('knowledge', ['id' => $k->id]);
    }

    // ---- testador (dry-run): linha da base ----------------------------------

    public function test_testador_informa_entradas_candidatas_no_modo_conhecimento(): void
    {
        Channel::create(['account_id' => $this->account->id, 'instance' => 'i', 'status' => 'connected']);
        AutoReplySetting::create(['account_id' => $this->account->id, 'ai_enabled' => true]);
        $contact = Contact::create([
            'account_id' => $this->account->id, 'remote_jid' => self::JID,
            'auto_reply_mode' => 'on', 'ai_enabled' => true, 'ai_mode' => 'conhecimento',
        ]);

        Knowledge::create(['account_id' => $this->account->id, 'title' => 'A', 'content' => 'a', 'sensitivity' => 'low', 'active' => true]);
        Knowledge::create(['account_id' => $this->account->id, 'title' => 'B', 'content' => 'b', 'sensitivity' => 'high', 'active' => true]);   // high NAO conta
        Knowledge::create(['account_id' => $this->account->id, 'title' => 'C', 'content' => 'c', 'sensitivity' => 'medium', 'active' => false]); // inativa NAO conta

        $res = app(RuleTester::class)->test($this->account->id, null, 'mensagem sem regra', $contact->id);

        $this->assertTrue($res['ok']);
        $this->assertFalse($res['matched']);
        $this->assertSame('conhecimento', $res['ai']['modo']);
        $this->assertSame(1, $res['ai']['base_candidatas']); // so a low ativa
    }

    public function test_testador_nao_conta_base_fora_do_modo_conhecimento(): void
    {
        Channel::create(['account_id' => $this->account->id, 'instance' => 'i', 'status' => 'connected']);
        AutoReplySetting::create(['account_id' => $this->account->id, 'ai_enabled' => true]);
        $contact = Contact::create([
            'account_id' => $this->account->id, 'remote_jid' => self::JID,
            'auto_reply_mode' => 'on', 'ai_enabled' => true, 'ai_mode' => 'intencao',
        ]);
        Knowledge::create(['account_id' => $this->account->id, 'title' => 'A', 'content' => 'a', 'sensitivity' => 'low', 'active' => true]);

        $res = app(RuleTester::class)->test($this->account->id, null, 'mensagem sem regra', $contact->id);

        $this->assertSame(0, $res['ai']['base_candidatas']);
    }
}
