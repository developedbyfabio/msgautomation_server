<?php

namespace Tests\Feature;

use App\Jobs\ProcessIncomingWhatsappMessage;
use App\Livewire\Configuracoes;
use App\Livewire\Contatos;
use App\Models\Account;
use App\Models\AutoReplyRule;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\FlowSession;
use App\Whatsapp\Proactive\ProactiveGuard;
use App\Whatsapp\Secrets\SecretVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proativas P-1 — A JAULA (nenhum caminho de envio proativo existe; provado por
 * Http::assertNothingSent em tudo que a fatia toca). Defaults D5 exatos, os 9
 * freios nomeados em ordem, contadores check/claim por conta (dia/semana SP),
 * trilha de consentimento auditavel, opt-out por palavra sem tocar o pipeline.
 */
class ProactiveGuardTest extends TestCase
{
    use RefreshDatabase;

    private const JID = '5541999990000@s.whatsapp.net';
    private Account $account;
    private Channel $channel;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        // Quarta 10h SP (dentro da janela default 09-18).
        Carbon::setTestNow(Carbon::create(2026, 7, 1, 10, 0, 0, 'America/Sao_Paulo'));
        Http::fake(['*' => Http::response(['key' => ['id' => 'PMID']], 201)]);

        $this->account = Account::create(['name' => 'T']);
        $this->channel = Channel::create(['account_id' => $this->account->id, 'instance' => 'fabio-pessoal', 'status' => 'connected']);
        AutoReplySetting::create([
            'account_id' => $this->account->id, 'enabled' => true, 'reply_policy' => 'all',
            'window_start' => '08:00:00', 'window_end' => '20:00:00',
            'min_interval_seconds' => 0, 'per_minute_cap' => 100, 'per_day_cap' => 100,
            'contact_rate_seconds' => 0, 'contact_rate_enabled' => false,
            'delay_min_seconds' => 0, 'delay_max_seconds' => 0,
        ]);
        $this->contact = Contact::create(['account_id' => $this->account->id, 'remote_jid' => self::JID, 'auto_reply_mode' => 'on', 'push_name' => 'Cliente']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function guard(): ProactiveGuard
    {
        return app(ProactiveGuard::class);
    }

    private function settings(): AutoReplySetting
    {
        return AutoReplySetting::withoutAccountScope()->where('account_id', $this->account->id)->first();
    }

    /** Liga a jaula + opt-in (base do caminho feliz dos testes do guard). */
    private function armar(): void
    {
        $this->settings()->update(['proactive_enabled' => true]);
        $this->contact->update(['proactive_opt_in' => true]);
    }

    private function receber(string $texto, string $id = 'W1', ?string $jid = null): void
    {
        (new ProcessIncomingWhatsappMessage([
            'event' => 'messages.upsert', 'instance' => 'fabio-pessoal',
            'data' => [
                'key' => ['id' => $id, 'fromMe' => false, 'remoteJid' => $jid ?: self::JID],
                'pushName' => 'Cliente', 'messageType' => 'conversation',
                'message' => ['conversation' => $texto], 'messageTimestamp' => 1782699162,
            ],
        ]))->handle(
            app(\App\Contracts\WhatsappGateway::class),
            app(\App\Whatsapp\AutoReply\RuleMatcher::class),
            app(\App\Whatsapp\AutoReply\AntiBanGuard::class),
        );
    }

    // ---- defaults D5 ------------------------------------------------------------

    public function test_defaults_d5_exatos_tudo_off(): void
    {
        $s = $this->settings();
        $this->assertFalse((bool) $s->proactive_enabled);        // kill switch nasce OFF
        $this->assertSame(20, (int) $s->proactive_daily_cap);
        $this->assertSame(1, (int) $s->proactive_per_contact_weekly_cap);
        $this->assertSame('09:00', substr((string) $s->proactive_window_start, 0, 5));
        $this->assertSame('18:00', substr((string) $s->proactive_window_end, 0, 5));
        $this->assertSame(3, (int) $s->proactive_jitter_min);
        $this->assertSame(15, (int) $s->proactive_jitter_max);
        $this->assertSame('PARAR', (string) $s->proactive_optout_word);
        // Nenhum contato nasce com opt-in.
        $this->assertFalse((bool) $this->contact->fresh()->proactive_opt_in);
        $this->assertSame(0, Contact::withoutAccountScope()->where('proactive_opt_in', true)->count());
    }

    // ---- os 9 freios, em ordem -----------------------------------------------------

    public function test_a_kill_switch_off_bloqueia_antes_de_tudo(): void
    {
        // Tudo mais estaria "errado" tambem (sem opt-in, teto zerado) — mas o motivo
        // e o switch: ele vem PRIMEIRO (nao gasta nem leitura de contador).
        $this->contact->update(['proactive_opt_in' => false]);

        $d = $this->guard()->allows($this->account->id, $this->contact->id, 'oi');
        $this->assertFalse($d->allowed);
        $this->assertSame('proactive_off', $d->reason);
        Http::assertNothingSent();
    }

    public function test_b_grupo_jamais(): void
    {
        $this->armar();
        $grupo = Contact::create(['account_id' => $this->account->id, 'remote_jid' => '123@g.us', 'proactive_opt_in' => true]);

        $this->assertSame('grupo', $this->guard()->allows($this->account->id, $grupo->id, 'oi')->reason);
    }

    public function test_c_contato_off_do_robo_jamais_recebe_proativa(): void
    {
        $this->armar();
        $this->contact->update(['auto_reply_mode' => 'off']);

        $this->assertSame('opt_out', $this->guard()->allows($this->account->id, $this->contact->id, 'oi')->reason);
    }

    public function test_d_sem_opt_in_bloqueia(): void
    {
        $this->settings()->update(['proactive_enabled' => true]);

        $this->assertSame('sem_opt_in', $this->guard()->allows($this->account->id, $this->contact->id, 'oi')->reason);
    }

    public function test_e_sessao_de_fluxo_ativa_bloqueia(): void
    {
        $this->armar();
        $flow = Flow::create(['account_id' => $this->account->id, 'name' => 'F', 'enabled' => true, 'timeout_seconds' => 600]);
        $node = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'menu', 'message' => 'M']);
        FlowSession::create([
            'account_id' => $this->account->id, 'flow_id' => $flow->id, 'remote_jid' => self::JID,
            'current_node_id' => $node->id, 'status' => 'active',
            'started_at' => now(), 'last_activity_at' => now(), 'expires_at' => now()->addMinutes(10),
        ]);

        $this->assertSame('fluxo_ativo', $this->guard()->allows($this->account->id, $this->contact->id, 'oi')->reason);
    }

    public function test_f_fora_da_janela_propria_bloqueia(): void
    {
        $this->armar();
        // 20h SP: dentro da janela do ROBO (ate 20h) mas FORA da proativa (ate 18h).
        $noite = Carbon::create(2026, 7, 1, 20, 0, 0, 'America/Sao_Paulo');

        $this->assertSame('fora_da_janela_proativa', $this->guard()->allows($this->account->id, $this->contact->id, 'oi', $noite)->reason);
    }

    public function test_g_teto_diario_da_conta_bloqueia(): void
    {
        $this->armar();
        $this->settings()->update(['proactive_daily_cap' => 2]);
        $outro = Contact::create(['account_id' => $this->account->id, 'remote_jid' => '5541888880000@s.whatsapp.net', 'auto_reply_mode' => 'on', 'proactive_opt_in' => true]);

        // Consome as 2 vagas do dia (contatos diferentes; semanal nao estoura).
        $this->assertTrue($this->guard()->claim($this->account->id, $this->contact->id));
        $this->assertTrue($this->guard()->claim($this->account->id, $outro->id));

        $terceiro = Contact::create(['account_id' => $this->account->id, 'remote_jid' => '5541777770000@s.whatsapp.net', 'auto_reply_mode' => 'on', 'proactive_opt_in' => true]);
        $this->assertSame('teto_dia_proativo', $this->guard()->allows($this->account->id, $terceiro->id, 'oi')->reason);
    }

    public function test_h_limite_semanal_do_contato_bloqueia(): void
    {
        $this->armar();
        $this->settings()->update(['proactive_daily_cap' => 20]);

        $this->assertTrue($this->guard()->claim($this->account->id, $this->contact->id)); // 1/semana consumida

        $this->assertSame('teto_semana_contato', $this->guard()->allows($this->account->id, $this->contact->id, 'oi')->reason);
    }

    public function test_i_conteudo_com_senha_e_proibido_sem_excecao(): void
    {
        $this->armar();

        $d = $this->guard()->allows($this->account->id, $this->contact->id, 'Sua senha e {senha:wifi}');
        $this->assertSame('contem_senha', $d->reason);
        Http::assertNothingSent();
    }

    public function test_caminho_feliz_tudo_ok_permite_sem_enviar_nada(): void
    {
        $this->armar();

        $d = $this->guard()->allows($this->account->id, $this->contact->id, 'Oi! Passando pra lembrar do orcamento.');
        $this->assertTrue($d->allowed);
        // A JAULA nao envia: allowed e so um veredito (disparo real e a P-3).
        Http::assertNothingSent();
    }

    // ---- contadores: check nao consome; claim atomico ------------------------------

    public function test_check_nao_consome_e_claim_consome(): void
    {
        $this->armar();

        $this->guard()->allows($this->account->id, $this->contact->id, 'oi');
        $this->guard()->allows($this->account->id, $this->contact->id, 'oi');
        $this->assertSame(0, $this->guard()->dayCount($this->account->id)); // check puro

        $this->assertTrue($this->guard()->claim($this->account->id, $this->contact->id));
        $this->assertSame(1, $this->guard()->dayCount($this->account->id));
        $this->assertSame(1, $this->guard()->weekCount($this->account->id, $this->contact->id));
    }

    public function test_claim_estourado_faz_rollback_completo(): void
    {
        $this->armar();
        $this->settings()->update(['proactive_per_contact_weekly_cap' => 1]);

        $this->assertTrue($this->guard()->claim($this->account->id, $this->contact->id));
        // 2o claim do MESMO contato: estoura o semanal -> rollback (nem o diario gasta).
        $this->assertFalse($this->guard()->claim($this->account->id, $this->contact->id));
        $this->assertSame(1, $this->guard()->dayCount($this->account->id));
        $this->assertSame(1, $this->guard()->weekCount($this->account->id, $this->contact->id));
    }

    public function test_contadores_isolados_entre_contas(): void
    {
        $b = Account::create(['name' => 'B']);
        AutoReplySetting::create(['account_id' => $b->id, 'proactive_enabled' => true]);
        $contatoB = Contact::create(['account_id' => $b->id, 'remote_jid' => self::JID, 'proactive_opt_in' => true]);
        $this->armar();

        $this->assertTrue($this->guard()->claim($this->account->id, $this->contact->id));

        // Conta B intocada (chaves por conta e por conta+contato).
        $this->assertSame(0, $this->guard()->dayCount($b->id));
        $this->assertSame(0, $this->guard()->weekCount($b->id, $contatoB->id));
    }

    // ---- opt-in manual com trilha ----------------------------------------------------

    public function test_toggle_manual_registra_grant_e_revoke(): void
    {
        Livewire::test(Contatos::class)
            ->call('startEdit', $this->contact->id)
            ->set('editProactiveOptIn', true)
            ->call('saveEdit');

        $this->assertTrue((bool) $this->contact->fresh()->proactive_opt_in);
        $this->assertDatabaseHas('proactive_consents', [
            'account_id' => $this->account->id, 'contact_id' => $this->contact->id,
            'action' => 'grant', 'origin' => 'manual',
        ]);

        Livewire::test(Contatos::class)
            ->call('startEdit', $this->contact->id)
            ->set('editProactiveOptIn', false)
            ->call('saveEdit');

        $this->assertFalse((bool) $this->contact->fresh()->proactive_opt_in);
        $this->assertDatabaseHas('proactive_consents', ['action' => 'revoke', 'origin' => 'manual']);
        // Trilha COMPLETA (grant + revoke; nada apagado).
        $this->assertSame(2, \App\Models\ProactiveConsent::withoutAccountScope()->count());
        // Salvar de novo SEM mudar: nao gera registro falso.
        Livewire::test(Contatos::class)->call('startEdit', $this->contact->id)->call('saveEdit');
        $this->assertSame(2, \App\Models\ProactiveConsent::withoutAccountScope()->count());
    }

    // ---- opt-out por palavra (pipeline INTOCADO) ---------------------------------------

    public function test_palavra_revoga_e_loga_sem_responder_nada(): void
    {
        $this->contact->update(['proactive_opt_in' => true]);

        $this->receber('parar'); // case-insensivel (default "PARAR")

        $this->assertFalse((bool) $this->contact->fresh()->proactive_opt_in);
        $this->assertDatabaseHas('proactive_consents', ['action' => 'revoke', 'origin' => 'palavra']);
        Http::assertNothingSent(); // nenhuma confirmacao automatica
    }

    public function test_palavra_com_acento_e_case_casa_e_mensagem_segue_o_pipeline(): void
    {
        $this->settings()->update(['proactive_optout_word' => 'CANCELAR']);
        $this->contact->update(['proactive_opt_in' => true]);
        // Regra reativa que casa a MESMA palavra: o pipeline segue normal (responde).
        $rule = AutoReplyRule::create(['account_id' => $this->account->id, 'match_type' => 'contains', 'match_value' => 'cancelar', 'response_text' => 'Ok, cancelado o recebimento.', 'enabled' => true]);
        $rule->triggers()->create(['match_type' => 'contains', 'match_value' => 'cancelar']);
        $rule->responses()->create(['response_text' => 'Ok, cancelado o recebimento.']);

        $this->receber('CanCelár');

        // Revogou (acento/case-insensivel, match exato)...
        $this->assertFalse((bool) $this->contact->fresh()->proactive_opt_in);
        // ...E a mensagem casou a regra reativa normalmente (pipeline intocado).
        Http::assertSent(fn ($r) => $r['text'] === 'Ok, cancelado o recebimento.');
    }

    public function test_palavra_dentro_de_frase_nao_revoga(): void
    {
        $this->contact->update(['proactive_opt_in' => true]);

        $this->receber('nao quero parar de receber'); // nao e match EXATO

        $this->assertTrue((bool) $this->contact->fresh()->proactive_opt_in);
        $this->assertDatabaseCount('proactive_consents', 0);
    }

    public function test_palavra_sem_opt_in_e_noop_sem_log_falso(): void
    {
        $this->receber('parar');

        $this->assertDatabaseCount('proactive_consents', 0);
    }

    public function test_palavra_em_grupo_e_ignorada(): void
    {
        $this->receber('parar', 'G1', '123456789@g.us');

        $this->assertDatabaseCount('proactive_consents', 0);
    }

    // ---- UI ------------------------------------------------------------------------------

    public function test_ui_ligar_switch_pede_confirmacao(): void
    {
        Livewire::test(Configuracoes::class)
            ->call('requestProactiveSwitch')
            ->assertSet('confirmingProactiveEnable', true);
        $this->assertFalse((bool) $this->settings()->proactive_enabled); // nada ate confirmar

        Livewire::test(Configuracoes::class)
            ->call('requestProactiveSwitch')
            ->call('proactiveEnableConfirmed');
        $this->assertTrue((bool) $this->settings()->proactive_enabled);
    }

    public function test_ui_afrouxar_pede_confirmacao_e_endurecer_salva_direto(): void
    {
        // ENDURECER (teto menor): direto.
        Livewire::test(Configuracoes::class)
            ->set('proactive_daily_cap', 10)
            ->call('saveProactive')
            ->assertSet('confirmingProactiveRelax', false);
        $this->assertSame(10, (int) $this->settings()->proactive_daily_cap);

        // AFROUXAR (acima de 20): confirmacao; nada aplicado ate confirmar.
        $c = Livewire::test(Configuracoes::class)
            ->set('proactive_daily_cap', 50)
            ->call('saveProactive')
            ->assertSet('confirmingProactiveRelax', true);
        $this->assertSame(10, (int) $this->settings()->proactive_daily_cap);
        $c->call('proactiveRelaxConfirmed');
        $this->assertSame(50, (int) $this->settings()->proactive_daily_cap);
    }

    public function test_ui_validacao_de_faixas(): void
    {
        Livewire::test(Configuracoes::class)
            ->set('proactive_daily_cap', 0)
            ->call('saveProactive')
            ->assertHasErrors('proactive_daily_cap');

        Livewire::test(Configuracoes::class)
            ->set('proactive_per_contact_weekly_cap', 9)
            ->call('saveProactive')
            ->assertHasErrors('proactive_per_contact_weekly_cap');

        Livewire::test(Configuracoes::class)
            ->set('proactive_window_end', '08:00') // fim antes do inicio
            ->call('saveProactive')
            ->assertHasErrors('proactive_window_end');
    }
}
