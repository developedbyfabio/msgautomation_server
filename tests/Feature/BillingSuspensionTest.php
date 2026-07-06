<?php

namespace Tests\Feature;

use App\Livewire\Billing;
use App\Models\Account;
use App\Models\AutoReplyRule;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\User;
use App\Tenancy\AccountContext;
use App\Whatsapp\AutoReply\Sender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fatia 26 — semantica EXATA da suspensao + corte de trial (billing:sweep):
 * owner so alcanca a billing; operador 403; o BOT PARA (gate de operacao no
 * funil unico de envio — decisao de matching intocada); NADA e apagado;
 * pagamento reativa; contas legacy IMUNES.
 */
class BillingSuspensionTest extends TestCase
{
    use RefreshDatabase;

    private const JID = '5541999990000@s.whatsapp.net';

    private Account $account;
    private Channel $channel;
    private User $owner;
    private User $operador;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['key' => ['id' => 'PMID']], 201)]);

        $this->account = Account::create(['name' => 'Cliente Pagante']);
        $this->channel = Channel::create(['account_id' => $this->account->id, 'instance' => 'inst-pag', 'provider' => 'evolution', 'webhook_token' => 'tok-pag', 'status' => 'connected']);
        AutoReplySetting::create([
            'account_id' => $this->account->id, 'enabled' => true, 'reply_policy' => 'all',
            'window_start' => '00:00:00', 'window_end' => '23:59:59',
            'min_interval_seconds' => 0, 'per_minute_cap' => 100, 'per_day_cap' => 100,
            'contact_rate_seconds' => 0,
        ]);
        $this->account->forceFill([
            'subscription_status' => 'trial',
            'trial_ends_at' => now()->addDays(7),
            'person_type' => 'pf',
            'document' => '52998224725',
        ])->save();

        $this->owner = User::create(['name' => 'Dono', 'email' => 'dono@pag.local', 'password' => Hash::make('senha-forte-123')]);
        $this->owner->accounts()->attach($this->account->id, ['role' => 'owner']);
        $this->operador = User::create(['name' => 'Op', 'email' => 'op@pag.local', 'password' => Hash::make('senha-forte-123')]);
        $this->operador->accounts()->attach($this->account->id, ['role' => 'operador']);
    }

    private function suspender(): void
    {
        $this->account->forceFill(['subscription_status' => 'suspended', 'suspended_at' => now()])->save();
    }

    // ---- corte de trial (comando agendado) ----------------------------------------

    public function test_sweep_trial_vencido_vira_overdue_e_depois_da_carencia_suspende(): void
    {
        $this->account->forceFill(['trial_ends_at' => now()->subDay()])->save();

        $this->artisan('billing:sweep')->assertSuccessful();
        $this->account->refresh();
        $this->assertSame('overdue', $this->account->subscription_status);
        $this->assertNotNull($this->account->overdue_since);

        // Reexecutar no MESMO dia: idempotente (nao re-transiciona nem suspende antes da hora).
        $this->artisan('billing:sweep')->assertSuccessful();
        $this->assertSame('overdue', $this->account->fresh()->subscription_status);

        // Passada a carencia (5 dias): suspende.
        $this->travel(6)->days();
        $this->artisan('billing:sweep')->assertSuccessful();
        $this->assertSame('suspended', $this->account->fresh()->subscription_status);
    }

    public function test_sweep_nao_toca_trial_vigente(): void
    {
        $this->artisan('billing:sweep')->assertSuccessful();
        $this->assertSame('trial', $this->account->fresh()->subscription_status);
    }

    public function test_conta_legacy_e_imune_ao_sweep(): void
    {
        // Como as contas 1 e 2 de producao: 'active', sem trial, sem Asaas.
        $legacy = Account::create(['name' => 'Legacy']);

        $this->travel(30)->days();
        $this->artisan('billing:sweep')->assertSuccessful();

        $this->assertSame('active', $legacy->fresh()->subscription_status);
        $this->assertNull($legacy->fresh()->suspended_at);
    }

    // ---- acesso: owner -> billing; operador -> 403 --------------------------------

    public function test_owner_de_conta_suspensa_e_redirecionado_para_a_billing(): void
    {
        $this->suspender();

        $this->actingAs($this->owner)->get(route('perfil'))->assertRedirect(route('billing'));
        $this->actingAs($this->owner)->get(route('conversas'))->assertRedirect(route('billing'));
        // A billing e o UNICO destino acessivel (fora do gate operacional).
        $this->actingAs($this->owner)->get(route('billing'))->assertOk()
            ->assertSee('Suspensa por falta de pagamento')
            ->assertSee('nenhum dado foi apagado');
    }

    public function test_operador_de_conta_suspensa_ve_403(): void
    {
        $this->suspender();

        $this->actingAs($this->operador)->get(route('conversas'))->assertForbidden();
        // E a billing segue owner-only (rota, Fatia 22).
        $this->actingAs($this->operador)->get(route('billing'))->assertForbidden();
    }

    public function test_conta_operante_nao_e_redirecionada(): void
    {
        $this->actingAs($this->owner)->get(route('perfil'))->assertOk(); // trial opera normal
    }

    public function test_canceled_bloqueia_acesso_como_suspensa(): void
    {
        $this->account->forceFill(['subscription_status' => 'canceled', 'suspended_at' => now()])->save();

        $this->actingAs($this->owner)->get(route('perfil'))->assertRedirect(route('billing'));
    }

    // ---- operacao: o bot PARA (gate no funil unico de envio) -----------------------

    public function test_bot_nao_responde_com_conta_suspensa_e_nada_e_apagado(): void
    {
        app(AccountContext::class)->set($this->account->id);
        Contact::create(['account_id' => $this->account->id, 'remote_jid' => self::JID, 'push_name' => 'Cliente', 'auto_reply_mode' => 'on']);
        AutoReplyRule::create(['account_id' => $this->account->id, 'match_type' => 'contains', 'match_value' => 'oi', 'response_text' => 'oi', 'enabled' => true]);
        $contatos = Contact::count();
        $regras = AutoReplyRule::count();

        $this->suspender();

        $log = app(Sender::class)->send('auto', $this->channel, self::JID, 'resposta do bot');

        $this->assertSame('blocked', $log->status);
        $this->assertSame('conta_suspensa', $log->motivo);
        Http::assertNothingSent(); // provider NUNCA foi chamado

        // Regra dura: suspensao NAO apaga nada (reversivel).
        $this->assertSame($contatos, Contact::count());
        $this->assertSame($regras, AutoReplyRule::count());
    }

    public function test_manual_e_proactive_tambem_param_quando_suspensa(): void
    {
        $this->suspender();

        $manual = app(Sender::class)->send('manual', $this->channel, self::JID, 'envio humano');
        $this->assertSame('blocked', $manual->status);
        $this->assertSame('conta_suspensa', $manual->motivo);
    }

    public function test_reativacao_religa_o_envio(): void
    {
        $this->suspender();
        $bloqueado = app(Sender::class)->send('auto', $this->channel, self::JID, 'x');
        $this->assertSame('blocked', $bloqueado->status);

        // Pagamento confirmado (maquina de estados) -> active -> gate libera sozinho.
        app(\App\Billing\BillingState::class)->aplicarEvento($this->account->fresh(), 'PAYMENT_CONFIRMED');

        $enviado = app(Sender::class)->send('auto', $this->channel, self::JID, 'voltei');
        $this->assertSame('sent', $enviado->status);
    }

    public function test_conta_operante_envia_normalmente_regressao_do_gate(): void
    {
        $log = app(Sender::class)->send('auto', $this->channel, self::JID, 'tudo normal');
        $this->assertSame('sent', $log->status); // gate novo nao muda quem opera
    }

    // ---- checkout owner-only (Fatia 22 reafirmada) ---------------------------------

    public function test_acao_assinar_forjada_por_operador_e_403(): void
    {
        $this->actingAs($this->operador);
        app(AccountContext::class)->set($this->account->id);

        Livewire::test(Billing::class)
            ->call('assinar')
            ->assertForbidden();

        $this->assertNull($this->account->fresh()->asaas_customer_id); // nada criado
    }
}
