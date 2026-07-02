<?php

namespace Tests\Feature;

use App\Ai\AiAnswer;
use App\Ai\AiAnswerRequest;
use App\Ai\AiClassification;
use App\Ai\AiClassificationRequest;
use App\Contracts\AiClassifier;
use App\Jobs\ClassifyWithAi;
use App\Livewire\Revisao;
use App\Models\Account;
use App\Models\AutoReplyLog;
use App\Models\AutoReplyRule;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\IncomingMessage;
use App\Models\Knowledge;
use App\Models\PendingApproval;
use App\Whatsapp\Secrets\SecretVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Camada 3 (IA) Fatia 3 — fila de aprovacao + /revisao. Driver MOCKADO (nunca API
 * real), HTTP mockado (nunca envio real). Provas: escala vira pendencia (idempotente
 * por mensagem), NADA sai sem clique, envio aprovado sai pelo Sender em modo
 * 'aprovacao' (kill switch nao bloqueia — politica do manual —, opt-out e R2 sim),
 * edicao nao insere {senha:} novo, segredo mascarado na UI, expiracao, isolamento
 * entre contas.
 */
class RevisaoTest extends TestCase
{
    use RefreshDatabase;

    private const JID = '5541999990000@s.whatsapp.net';
    private Account $account;
    private Channel $channel;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 7, 2, 10, 0, 0, 'America/Sao_Paulo'));
        Http::fake(['*' => Http::response(['key' => ['id' => 'PMID']], 201)]);

        $this->account = Account::create(['name' => 'T']);
        $this->channel = Channel::create(['account_id' => $this->account->id, 'instance' => 'fabio-pessoal', 'status' => 'connected']);
        AutoReplySetting::create([
            'account_id' => $this->account->id, 'enabled' => true, 'reply_policy' => 'all',
            'window_start' => '08:00:00', 'window_end' => '20:00:00',
            'min_interval_seconds' => 0, 'per_minute_cap' => 100, 'per_day_cap' => 100,
            'contact_rate_seconds' => 0, 'contact_rate_enabled' => false,
            'delay_min_seconds' => 0, 'delay_max_seconds' => 0,
            'ai_enabled' => true, 'ai_confidence_threshold' => 0.75,
        ]);
        $this->contact = Contact::create([
            'account_id' => $this->account->id, 'remote_jid' => self::JID,
            'auto_reply_mode' => 'on', 'ai_enabled' => true, 'ai_mode' => 'intencao', 'push_name' => 'Cliente',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function incoming(string $texto, string $id = 'IM1', ?string $jid = null): IncomingMessage
    {
        return IncomingMessage::create([
            'account_id' => $this->account->id, 'channel_id' => $this->channel->id,
            'instance' => $this->channel->instance, 'evolution_message_id' => $id,
            'remote_jid' => $jid ?: self::JID, 'from_me' => false, 'push_name' => 'Cliente',
            'type' => 'conversation', 'text' => $texto, 'raw_payload' => ['x' => 1], 'received_at' => now(),
        ]);
    }

    private function ruleHoras(string $resposta = 'Sao 10h.'): AutoReplyRule
    {
        $rule = AutoReplyRule::create([
            'account_id' => $this->account->id, 'match_type' => 'contains', 'match_value' => 'que horas sao',
            'response_text' => $resposta, 'enabled' => true, 'ai_match_enabled' => true,
        ]);
        $rule->triggers()->create(['match_type' => 'contains', 'match_value' => 'que horas sao']);
        $rule->responses()->create(['response_text' => $resposta]);

        return $rule->fresh();
    }

    private function runJob(int $incomingId, ?AiClassification $classify = null, ?AiAnswer $answer = null): void
    {
        (new ClassifyWithAi($incomingId))->handle(
            new FakeRevisaoAi($classify, $answer),
            app(\App\Whatsapp\AutoReply\AntiBanGuard::class),
            app(\App\Whatsapp\AutoReply\RuleMatcher::class),
            app(SecretVault::class),
            app(\App\Whatsapp\AutoReply\RuleResponder::class),
        );
    }

    /** Pendencia pronta pra testes da tela (sem passar pelo pipeline). */
    private function pendencia(array $extra = []): PendingApproval
    {
        $im = $extra['incoming'] ?? $this->incoming('qual o preco?', 'IM-' . uniqid());
        unset($extra['incoming']);

        return PendingApproval::create(array_merge([
            'account_id' => $this->account->id,
            'contact_id' => $this->contact->id,
            'incoming_message_id' => $im->id,
            'remote_jid' => self::JID,
            'suggested_response' => 'Custa 100 reais.',
            'origin' => 'regra',
            'reason' => 'baixa_confianca',
            'confidence' => 0.5,
            'status' => 'pending',
        ], $extra));
    }

    // ---- geracao de pendencias (pipeline) ------------------------------------

    public function test_escala_por_limiar_cria_pendencia_com_sugestao_da_regra(): void
    {
        $rule = $this->ruleHoras('Sao 10h da manha.');
        $im = $this->incoming('nao sei que horas');

        $this->runJob($im->id, new AiClassification('horario', 0.5, $rule->id, true, false, 'incerto'));

        $this->assertDatabaseHas('pending_approvals', [
            'incoming_message_id' => $im->id, 'origin' => 'regra', 'reason' => 'baixa_confianca',
            'suggested_response' => 'Sao 10h da manha.', 'status' => 'pending', 'contact_id' => $this->contact->id,
        ]);
        $p = PendingApproval::where('incoming_message_id', $im->id)->first();
        $this->assertNotNull($p->ai_decision_id);
        $this->assertDatabaseHas('ai_decisions', ['id' => $p->ai_decision_id, 'acao' => 'escalou', 'motivo' => 'baixa_confianca']);
        Http::assertNothingSent(); // escalou = nada enviado
    }

    public function test_escala_da_base_por_tema_cria_pendencia_com_resposta_fundamentada(): void
    {
        Contact::where('id', $this->contact->id)->update(['ai_mode' => 'conhecimento']);
        $k = Knowledge::create(['account_id' => $this->account->id, 'title' => 'Precos', 'content' => 'O produto custa 100 reais.', 'sensitivity' => 'low', 'active' => true]);
        $im = $this->incoming('quanto custa pra pagar no pix?');

        $this->runJob($im->id, answer: new AiAnswer('O produto custa 100 reais.', true, 0.95, true, [$k->id], 'fala de valores', null));

        $this->assertDatabaseHas('pending_approvals', [
            'incoming_message_id' => $im->id, 'origin' => 'base', 'reason' => 'tema_aprovacao',
            'suggested_response' => 'O produto custa 100 reais.', 'status' => 'pending',
        ]);
        Http::assertNothingSent();
    }

    public function test_conteudo_high_cria_pendencia_sem_sugestao(): void
    {
        Contact::where('id', $this->contact->id)->update(['ai_mode' => 'conhecimento']);
        Knowledge::create(['account_id' => $this->account->id, 'title' => 'Banco', 'content' => 'agencia 1234', 'sensitivity' => 'high', 'active' => true]);
        $im = $this->incoming('qual a conta pra deposito?');

        $this->runJob($im->id);

        $this->assertDatabaseHas('pending_approvals', [
            'incoming_message_id' => $im->id, 'origin' => 'base', 'reason' => 'conteudo_high',
            'suggested_response' => null, 'status' => 'pending',
        ]);
    }

    public function test_sem_pendencia_duplicada_pra_mesma_mensagem(): void
    {
        $rule = $this->ruleHoras();
        $im = $this->incoming('nao sei que horas');

        // Corrida simulada: pendencia ja existe pra mensagem; o job escala de novo
        // (sem decisao previa) -> o catch do indice unico mantem a primeira.
        $this->pendencia(['incoming' => $im, 'suggested_response' => 'PRIMEIRA']);
        $this->runJob($im->id, new AiClassification('horario', 0.5, $rule->id, true, false, 'incerto'));

        $this->assertSame(1, PendingApproval::where('incoming_message_id', $im->id)->count());
        $this->assertDatabaseHas('pending_approvals', ['incoming_message_id' => $im->id, 'suggested_response' => 'PRIMEIRA']);
    }

    public function test_valor_de_segredo_nunca_persistido_na_pendencia(): void
    {
        app(SecretVault::class)->put($this->account->id, 'wifi', 'segredoDoWifi123');
        $rule = $this->ruleHoras('A senha do wifi e {senha:wifi}');
        $im = $this->incoming('qual a senha do wifi?');

        $this->runJob($im->id, new AiClassification('wifi', 0.99, $rule->id, true, false, 'ok'));

        $p = PendingApproval::where('incoming_message_id', $im->id)->first();
        $this->assertSame('contem_senha', $p->reason);
        $this->assertStringContainsString('{senha:wifi}', (string) $p->suggested_response); // placeholder, nunca o valor
        $this->assertStringNotContainsString('segredoDoWifi123', (string) $p->suggested_response);
    }

    // ---- acoes da tela: Enviar ------------------------------------------------

    public function test_enviar_despacha_pelo_sender_e_trava_a_pendencia(): void
    {
        $p = $this->pendencia(['suggested_response' => '{saudacao}, {nome}! Custa 100 reais.']);

        Livewire::test(Revisao::class)
            ->call('askSend', $p->id)
            ->call('confirmSend');

        // Placeholders resolvidos SO no envio (10h -> Bom dia; nome do contato).
        Http::assertSent(fn ($r) => $r['text'] === 'Bom dia, Cliente! Custa 100 reais.');
        $p->refresh();
        $this->assertSame('approved', $p->status);
        $this->assertNotNull($p->decided_at);
        $this->assertDatabaseHas('auto_reply_logs', [
            'id' => $p->sent_auto_reply_log_id, 'mode' => 'aprovacao', 'status' => 'sent',
            'incoming_message_id' => $p->incoming_message_id,
        ]);
    }

    public function test_enviar_com_kill_switch_do_robo_off_envia_mesmo_assim(): void
    {
        // Politica do envio manual (R1): decisao humana nao passa pelo kill switch.
        AutoReplySetting::where('account_id', $this->account->id)->update(['enabled' => false]);
        $p = $this->pendencia();

        Livewire::test(Revisao::class)
            ->call('askSend', $p->id)
            ->call('confirmSend');

        Http::assertSentCount(1);
        $this->assertSame('approved', $p->fresh()->status);
    }

    public function test_opt_out_no_meio_segura_o_envio_e_mantem_pendente(): void
    {
        $p = $this->pendencia();
        // Contato virou 'off' entre a escala e o clique -> guarda protetiva segura.
        Contact::where('id', $this->contact->id)->update(['auto_reply_mode' => 'off']);

        Livewire::test(Revisao::class)
            ->call('askSend', $p->id)
            ->call('confirmSend');

        Http::assertNothingSent();
        $p->refresh();
        $this->assertSame('pending', $p->status); // continua na fila, nada decidido
        $this->assertDatabaseHas('auto_reply_logs', ['incoming_message_id' => $p->incoming_message_id, 'status' => 'blocked', 'motivo' => 'opt_out']);
    }

    public function test_teto_protetivo_vale_pro_envio_aprovado(): void
    {
        AutoReplySetting::where('account_id', $this->account->id)->update(['per_day_enabled' => true, 'per_day_cap' => 0]);
        $p = $this->pendencia();

        Livewire::test(Revisao::class)
            ->call('askSend', $p->id)
            ->call('confirmSend');

        Http::assertNothingSent();
        $this->assertSame('pending', $p->fresh()->status);
    }

    public function test_sem_sugestao_nao_ha_botao_enviar_direto(): void
    {
        $p = $this->pendencia(['suggested_response' => null]);

        Livewire::test(Revisao::class)
            ->call('askSend', $p->id)   // guarda: nao abre confirmacao sem sugestao
            ->call('confirmSend');

        Http::assertNothingSent();
        $this->assertSame('pending', $p->fresh()->status);
    }

    // ---- acoes da tela: Editar --------------------------------------------------

    public function test_editar_envia_o_texto_editado(): void
    {
        $p = $this->pendencia();

        Livewire::test(Revisao::class)
            ->call('startEdit', $p->id)
            ->assertSet('editText', 'Custa 100 reais.')
            ->set('editText', 'Custa 90 reais com desconto, {nome}.')
            ->call('confirmEdit');

        Http::assertSent(fn ($r) => $r['text'] === 'Custa 90 reais com desconto, Cliente.');
        $this->assertSame('edited', $p->fresh()->status);
    }

    public function test_editar_nao_pode_inserir_senha_nova(): void
    {
        app(SecretVault::class)->put($this->account->id, 'wifi', 'segredoDoWifi123');
        $p = $this->pendencia(); // sugestao SEM {senha:}

        Livewire::test(Revisao::class)
            ->call('startEdit', $p->id)
            ->set('editText', 'A senha e {senha:wifi}')
            ->call('confirmEdit')
            ->assertHasErrors('editText');

        Http::assertNothingSent();
        $this->assertSame('pending', $p->fresh()->status);
    }

    public function test_editar_pode_manter_senha_que_ja_veio_na_sugestao(): void
    {
        app(SecretVault::class)->put($this->account->id, 'wifi', 'segredoDoWifi123');
        $p = $this->pendencia(['suggested_response' => 'A senha do wifi e {senha:wifi}', 'reason' => 'contem_senha']);

        Livewire::test(Revisao::class)
            ->call('startEdit', $p->id)
            ->set('editText', 'Oi {nome}! A senha do wifi e {senha:wifi}')
            ->call('confirmEdit')
            ->assertHasNoErrors();

        // Valor resolvido SO no POST; log guarda a REDACAO.
        Http::assertSent(fn ($r) => $r['text'] === 'Oi Cliente! A senha do wifi e segredoDoWifi123');
        $this->assertSame('edited', $p->fresh()->status);
        $this->assertDatabaseHas('auto_reply_logs', ['id' => $p->fresh()->sent_auto_reply_log_id, 'response_text' => 'Oi Cliente! A senha do wifi e [senha: wifi]']);
    }

    // ---- acoes da tela: Ignorar / trava ------------------------------------------

    public function test_ignorar_marca_rejected_sem_enviar(): void
    {
        $p = $this->pendencia();

        Livewire::test(Revisao::class)->call('ignore', $p->id);

        Http::assertNothingSent();
        $p->refresh();
        $this->assertSame('rejected', $p->status);
        $this->assertNotNull($p->decided_at);
    }

    public function test_pendencia_decidida_trava_sem_reenvio(): void
    {
        $p = $this->pendencia(['status' => 'rejected', 'decided_at' => now()]);

        Livewire::test(Revisao::class)
            ->call('askSend', $p->id)
            ->call('confirmSend')
            ->call('startEdit', $p->id)
            ->call('ignore', $p->id);

        Http::assertNothingSent();
        $this->assertSame('rejected', $p->fresh()->status);
    }

    // ---- segredo mascarado na UI ---------------------------------------------------

    public function test_segredo_aparece_mascarado_e_valor_nunca_na_tela(): void
    {
        app(SecretVault::class)->put($this->account->id, 'wifi', 'segredoDoWifi123');
        $this->pendencia(['suggested_response' => 'A senha do wifi e {senha:wifi}', 'reason' => 'contem_senha']);

        Livewire::test(Revisao::class)
            ->assertSee('[senha: wifi')          // mascarado (placeholder visivel)
            ->assertDontSee('segredoDoWifi123'); // valor NUNCA
    }

    // ---- expiracao -------------------------------------------------------------------

    public function test_pendencia_velha_expira_e_nao_e_enviavel(): void
    {
        $p = $this->pendencia();
        PendingApproval::where('id', $p->id)->update(['created_at' => now()->subDays(8)]);

        // mount -> expiracao lazy
        $component = Livewire::test(Revisao::class);
        $this->assertSame('expired', $p->fresh()->status);

        $component->call('askSend', $p->id)->call('confirmSend');
        Http::assertNothingSent();
        $this->assertSame('expired', $p->fresh()->status);
    }

    public function test_comando_expira_pendencias_velhas(): void
    {
        $velha = $this->pendencia();
        PendingApproval::where('id', $velha->id)->update(['created_at' => now()->subDays(8)]);
        $nova = $this->pendencia(['incoming' => $this->incoming('outra', 'IM2')]);

        $this->artisan('ai:expire-approvals')->assertSuccessful();

        $this->assertSame('expired', $velha->fresh()->status);
        $this->assertSame('pending', $nova->fresh()->status);
    }

    // ---- isolamento entre contas -------------------------------------------------------

    public function test_pendencia_de_outra_conta_e_invisivel_e_inacionavel(): void
    {
        // Conta B com dados espelhados. A tela opera na conta-ancora (A, oldest).
        $accountB = Account::create(['name' => 'B']);
        $channelB = Channel::create(['account_id' => $accountB->id, 'instance' => 'conta-b', 'status' => 'connected']);
        $contactB = Contact::create(['account_id' => $accountB->id, 'remote_jid' => '5541777770000@s.whatsapp.net', 'push_name' => 'ClienteB']);
        $imB = IncomingMessage::create([
            'account_id' => $accountB->id, 'channel_id' => $channelB->id, 'instance' => 'conta-b',
            'evolution_message_id' => 'B1', 'remote_jid' => $contactB->remote_jid, 'from_me' => false,
            'type' => 'conversation', 'text' => 'SEGREDO-DA-CONTA-B', 'raw_payload' => [], 'received_at' => now(),
        ]);
        $pB = PendingApproval::create([
            'account_id' => $accountB->id, 'contact_id' => $contactB->id, 'incoming_message_id' => $imB->id,
            'remote_jid' => $contactB->remote_jid, 'suggested_response' => 'RESPOSTA-DA-CONTA-B',
            'origin' => 'regra', 'reason' => 'baixa_confianca', 'status' => 'pending',
        ]);

        Livewire::test(Revisao::class)
            // Invisivel: nada da conta B na lista da conta A.
            ->assertDontSee('SEGREDO-DA-CONTA-B')
            ->assertDontSee('RESPOSTA-DA-CONTA-B')
            ->assertDontSee('ClienteB')
            // Inacionavel: acoes na pendencia da B sao no-op.
            ->call('askSend', $pB->id)
            ->call('confirmSend')
            ->call('ignore', $pB->id);

        Http::assertNothingSent();
        $this->assertSame('pending', $pB->fresh()->status);
    }
}

/** Driver falso — nunca API real. Defaults: "nenhuma regra casou" / "nao sei". */
class FakeRevisaoAi implements AiClassifier
{
    public function __construct(
        private ?AiClassification $classifyResult = null,
        private ?AiAnswer $answerResult = null,
    ) {
    }

    public function classify(AiClassificationRequest $request): AiClassification
    {
        return $this->classifyResult ?? new AiClassification('', 0.9, null, false, false, 'nenhuma');
    }

    public function answer(AiAnswerRequest $request): AiAnswer
    {
        return $this->answerResult ?? AiAnswer::unknown('sem_mock');
    }
}
