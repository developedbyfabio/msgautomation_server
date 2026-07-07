<?php

namespace Tests\Feature;

use App\Actions\ClearAccountConversations;
use App\Livewire\Configuracoes;
use App\Models\Account;
use App\Models\AutoReplyLog;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\IncomingMessage;
use App\Models\SystemEvent;
use App\Models\User;
use App\Tenancy\AccountContext;
use App\Whatsapp\SystemConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Feature 1 — "Limpar todas as conversas" (HARD DELETE owner-only). As quatro
 * salvaguardas obrigatorias: confirmacao, owner-only server-side, escopo de
 * conta (nunca cruza), auditoria. Decisao de escopo: apaga MENSAGENS
 * (incoming_messages + auto_reply_logs) + artefatos por-mensagem; PRESERVA
 * contatos e a conversa de sistema.
 */
class LimparConversasTest extends TestCase
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
        $this->operador = User::create(['name' => 'Op', 'email' => 'op@x.local', 'password' => Hash::make('senha-forte-123')]);
        $this->operador->accounts()->attach($this->account->id, ['role' => 'operador']);
    }

    private function msgs(int $accountId, string $jid, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            IncomingMessage::withoutAccountScope()->create([
                'account_id' => $accountId, 'instance' => 'i', 'evolution_message_id' => "in-{$accountId}-{$jid}-{$i}",
                'remote_jid' => $jid, 'from_me' => false, 'type' => 'conversation', 'text' => "m{$i}",
                'raw_payload' => [], 'received_at' => now(),
            ]);
            AutoReplyLog::withoutAccountScope()->create([
                'account_id' => $accountId, 'remote_jid' => $jid, 'mode' => 'manual',
                'response_text' => "r{$i}", 'status' => 'sent', 'sent_at' => now(),
            ]);
        }
    }

    private function comoOwner(): void
    {
        app(AccountContext::class)->set($this->account->id);
        $this->actingAs($this->owner);
    }

    // ---- apaga de verdade + confirmação ---------------------------------------

    public function test_owner_confirma_e_conversas_somem_do_banco(): void
    {
        $this->msgs($this->account->id, '5511999@s.whatsapp.net', 3);
        $this->assertSame(3, IncomingMessage::withoutAccountScope()->where('account_id', $this->account->id)->count());

        $this->comoOwner();
        Livewire::test(Configuracoes::class)
            ->call('askClearConversations')
            ->assertSet('confirmingClearConversations', true)
            ->assertSet('clearCount', 6) // 3 recebidas + 3 enviadas
            ->call('clearConversationsConfirmed');

        // Read-back: zero mensagens da conta.
        $this->assertSame(0, IncomingMessage::withoutAccountScope()->where('account_id', $this->account->id)->count());
        $this->assertSame(0, AutoReplyLog::withoutAccountScope()->where('account_id', $this->account->id)->count());
    }

    public function test_cancelar_nao_apaga_nada(): void
    {
        $this->msgs($this->account->id, '5511999@s.whatsapp.net', 2);
        $this->comoOwner();

        Livewire::test(Configuracoes::class)
            ->call('askClearConversations')
            ->call('cancelClearConversations')
            ->assertSet('confirmingClearConversations', false);

        $this->assertSame(2, IncomingMessage::withoutAccountScope()->where('account_id', $this->account->id)->count());
    }

    // ---- isolamento entre contas ----------------------------------------------

    public function test_apagar_na_conta_1_nao_afeta_a_conta_2(): void
    {
        $b = Account::create(['name' => 'B']);
        $this->msgs($this->account->id, '5511111@s.whatsapp.net', 2);
        $this->msgs($b->id, '5522222@s.whatsapp.net', 4);

        $this->comoOwner(); // contexto = conta A
        Livewire::test(Configuracoes::class)->call('askClearConversations')->call('clearConversationsConfirmed');

        $this->assertSame(0, IncomingMessage::withoutAccountScope()->where('account_id', $this->account->id)->count());
        // Conta B intacta.
        $this->assertSame(4, IncomingMessage::withoutAccountScope()->where('account_id', $b->id)->count());
        $this->assertSame(4, AutoReplyLog::withoutAccountScope()->where('account_id', $b->id)->count());
    }

    // ---- owner-only server-side -----------------------------------------------

    public function test_operador_recebe_403_na_acao(): void
    {
        $this->msgs($this->account->id, '5511999@s.whatsapp.net', 2);
        app(AccountContext::class)->set($this->account->id);
        $this->actingAs($this->operador);

        Livewire::test(Configuracoes::class)
            ->call('clearConversationsConfirmed')
            ->assertForbidden();

        // Nada apagado.
        $this->assertSame(2, IncomingMessage::withoutAccountScope()->where('account_id', $this->account->id)->count());
    }

    // ---- auditoria (sem conteúdo) ---------------------------------------------

    public function test_grava_systemevent_de_auditoria_sem_conteudo(): void
    {
        $this->msgs($this->account->id, '5511999@s.whatsapp.net', 2);
        $this->comoOwner();

        Livewire::test(Configuracoes::class)->call('askClearConversations')->call('clearConversationsConfirmed');

        $ev = SystemEvent::withoutAccountScope()->where('type', 'conversas')->first();
        $this->assertNotNull($ev);
        $this->assertSame($this->account->id, $ev->account_id);
        $this->assertSame($this->owner->id, $ev->detail['user_id']);
        $this->assertSame(4, $ev->detail['mensagens_apagadas']);
        // NUNCA o conteudo das mensagens.
        $this->assertStringNotContainsString('m0', json_encode($ev->getAttributes()));
        $this->assertStringNotContainsString('r0', json_encode($ev->getAttributes()));
    }

    // ---- preserva contatos e a conversa de sistema ----------------------------

    public function test_preserva_contatos_e_conversa_de_sistema(): void
    {
        // Contato real + conversa de sistema com mensagem.
        Contact::withoutAccountScope()->create(['account_id' => $this->account->id, 'remote_jid' => '5511999@s.whatsapp.net', 'push_name' => 'Cliente']);
        app(SystemConversation::class)->record($this->account->id, 'Alerta X', 'srv-alert:1:firing');
        $this->msgs($this->account->id, '5511999@s.whatsapp.net', 2);

        $this->comoOwner();
        Livewire::test(Configuracoes::class)->call('askClearConversations')->call('clearConversationsConfirmed');

        // Contato preservado (nao apaga clientes).
        $this->assertSame(2, Contact::withoutAccountScope()->where('account_id', $this->account->id)->count()); // cliente + sistema
        // Conversa de sistema preservada (mensagem de alerta continua).
        $this->assertSame(1, IncomingMessage::withoutAccountScope()->where('remote_jid', SystemConversation::JID)->count());
        // A conversa normal foi apagada.
        $this->assertSame(0, IncomingMessage::withoutAccountScope()->where('remote_jid', '5511999@s.whatsapp.net')->count());
    }

    public function test_action_isolada_conta_e_count(): void
    {
        $this->msgs($this->account->id, '5511999@s.whatsapp.net', 3);
        $action = app(ClearAccountConversations::class);
        $this->assertSame(6, $action->count($this->account->id));
        $apagadas = $action->handle($this->account->id, $this->owner->id);
        $this->assertSame(6, $apagadas);
        $this->assertSame(0, $action->count($this->account->id));
    }
}
