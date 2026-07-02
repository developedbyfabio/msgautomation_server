<?php

namespace Tests\Feature;

use App\Ai\AiAnswer;
use App\Ai\AiAnswerRequest;
use App\Ai\AiClassification;
use App\Ai\AiClassificationRequest;
use App\Contracts\AiClassifier;
use App\Jobs\ClassifyWithAi;
use App\Livewire\Configuracoes;
use App\Models\Account;
use App\Models\AutoReplyRule;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\IncomingMessage;
use App\Whatsapp\Secrets\SecretVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Camada 3/5 Fatia 4 — limiar de confianca e temas de aprovacao EDITAVEIS na UI.
 * Provas: persistencia + validacao de faixa; AFROUXAR (reduzir limiar < 0.70 /
 * desmarcar tema) exige confirmacao em modal; o limiar editado muda a decisao do
 * pipeline; os temas editados sao exatamente o que vai ao modelo; isolamento por
 * conta. Driver MOCKADO, HTTP mockado.
 */
class ConfigIaTest extends TestCase
{
    use RefreshDatabase;

    private const JID = '5541999990000@s.whatsapp.net';
    private Account $account;
    private Channel $channel;

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
        Contact::create([
            'account_id' => $this->account->id, 'remote_jid' => self::JID,
            'auto_reply_mode' => 'on', 'ai_enabled' => true, 'ai_mode' => 'intencao',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function settings(): AutoReplySetting
    {
        return AutoReplySetting::where('account_id', $this->account->id)->first();
    }

    private function ruleHoras(): AutoReplyRule
    {
        $rule = AutoReplyRule::create([
            'account_id' => $this->account->id, 'match_type' => 'contains', 'match_value' => 'que horas sao',
            'response_text' => 'Sao 10h.', 'enabled' => true, 'ai_match_enabled' => true,
        ]);
        $rule->triggers()->create(['match_type' => 'contains', 'match_value' => 'que horas sao']);
        $rule->responses()->create(['response_text' => 'Sao 10h.']);

        return $rule->fresh();
    }

    private function incoming(string $texto, string $id): IncomingMessage
    {
        return IncomingMessage::create([
            'account_id' => $this->account->id, 'channel_id' => $this->channel->id,
            'instance' => $this->channel->instance, 'evolution_message_id' => $id,
            'remote_jid' => self::JID, 'from_me' => false, 'type' => 'conversation',
            'text' => $texto, 'raw_payload' => ['x' => 1], 'received_at' => now(),
        ]);
    }

    private function runJob(int $incomingId, FakeConfigAi $fake): void
    {
        (new ClassifyWithAi($incomingId))->handle(
            $fake,
            app(\App\Whatsapp\AutoReply\AntiBanGuard::class),
            app(\App\Whatsapp\AutoReply\RuleMatcher::class),
            app(SecretVault::class),
            app(\App\Whatsapp\AutoReply\RuleResponder::class),
        );
    }

    // ---- limiar ---------------------------------------------------------------

    public function test_subir_o_limiar_salva_direto(): void
    {
        Livewire::test(Configuracoes::class)
            ->set('ai_confidence_threshold', 0.85)
            ->call('saveAi')
            ->assertSet('confirmingAiRelax', false)
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(0.85, (float) $this->settings()->ai_confidence_threshold, 0.001);
    }

    public function test_faixa_do_limiar_e_validada(): void
    {
        Livewire::test(Configuracoes::class)
            ->set('ai_confidence_threshold', 0.30)
            ->call('saveAi')
            ->assertHasErrors('ai_confidence_threshold');

        Livewire::test(Configuracoes::class)
            ->set('ai_confidence_threshold', 0.99)
            ->call('saveAi')
            ->assertHasErrors('ai_confidence_threshold');

        $this->assertEqualsWithDelta(0.75, (float) $this->settings()->ai_confidence_threshold, 0.001);
    }

    public function test_reduzir_abaixo_de_070_pede_confirmacao(): void
    {
        $c = Livewire::test(Configuracoes::class)
            ->set('ai_confidence_threshold', 0.65)
            ->call('saveAi')
            ->assertSet('confirmingAiRelax', true);

        // Nada aplicado ate confirmar.
        $this->assertEqualsWithDelta(0.75, (float) $this->settings()->ai_confidence_threshold, 0.001);

        $c->call('aiRelaxConfirmed');
        $this->assertEqualsWithDelta(0.65, (float) $this->settings()->ai_confidence_threshold, 0.001);
    }

    public function test_cancelar_o_afrouxamento_restaura_os_campos(): void
    {
        Livewire::test(Configuracoes::class)
            ->set('ai_confidence_threshold', 0.55)
            ->call('saveAi')
            ->assertSet('confirmingAiRelax', true)
            ->call('cancelAiRelax')
            ->assertSet('ai_confidence_threshold', 0.75)
            ->assertSet('confirmingAiRelax', false);

        $this->assertEqualsWithDelta(0.75, (float) $this->settings()->ai_confidence_threshold, 0.001);
    }

    public function test_reduzir_ate_070_nao_pede_confirmacao(): void
    {
        Livewire::test(Configuracoes::class)
            ->set('ai_confidence_threshold', 0.70)
            ->call('saveAi')
            ->assertSet('confirmingAiRelax', false);

        $this->assertEqualsWithDelta(0.70, (float) $this->settings()->ai_confidence_threshold, 0.001);
    }

    public function test_limiar_editado_muda_a_decisao_do_pipeline(): void
    {
        $rule = $this->ruleHoras();

        // Limiar 0.70: confianca 0.72 fica ACIMA -> responde.
        $this->settings()->update(['ai_confidence_threshold' => 0.70]);
        $fake = new FakeConfigAi(new AiClassification('horario', 0.72, $rule->id, true, false, 'ok'));
        $this->runJob($this->incoming('me fala a hora', 'IM1')->id, $fake);
        $this->assertDatabaseHas('ai_decisions', ['acao' => 'respondeu']);

        // Limiar 0.75: a MESMA confianca 0.72 fica abaixo -> escala.
        $this->settings()->update(['ai_confidence_threshold' => 0.75]);
        $im2 = $this->incoming('me fala a hora de novo', 'IM2');
        $this->runJob($im2->id, new FakeConfigAi(new AiClassification('horario', 0.72, $rule->id, true, false, 'ok')));
        $this->assertDatabaseHas('ai_decisions', ['incoming_message_id' => $im2->id, 'acao' => 'escalou', 'motivo' => 'baixa_confianca']);
    }

    // ---- temas de aprovacao ------------------------------------------------------

    public function test_desmarcar_tema_pede_confirmacao_e_persiste(): void
    {
        // Default: NULL = todos os 4 temas.
        $semPagamento = ['dados_bancarios', 'compromissos', 'conteudo_high'];

        $c = Livewire::test(Configuracoes::class)
            ->set('ai_approval_topics', $semPagamento)
            ->call('saveAi')
            ->assertSet('confirmingAiRelax', true);

        $this->assertNull($this->settings()->ai_approval_topics); // nada aplicado ainda

        $c->call('aiRelaxConfirmed');
        $this->assertSame($semPagamento, $this->settings()->fresh()->aiApprovalTopics());
    }

    public function test_marcar_tema_de_volta_salva_direto(): void
    {
        $this->settings()->update(['ai_approval_topics' => ['pagamento']]);

        Livewire::test(Configuracoes::class)
            ->set('ai_approval_topics', ['pagamento', 'compromissos'])
            ->call('saveAi')
            ->assertSet('confirmingAiRelax', false);

        $this->assertSame(['pagamento', 'compromissos'], $this->settings()->fresh()->aiApprovalTopics());
    }

    public function test_temas_configurados_sao_o_que_vai_ao_modelo(): void
    {
        $rule = $this->ruleHoras();
        $this->settings()->update(['ai_approval_topics' => ['pagamento']]);

        $fake = new FakeConfigAi(new AiClassification('', 0.9, null, false, false, 'nenhuma'));
        $this->runJob($this->incoming('me fala a hora', 'IM3')->id, $fake);

        // O modelo marca needs_approval com base NESTA lista — editar os temas muda
        // exatamente o que a IA trata como sensivel daqui pra frente.
        $this->assertSame(['pagamento'], $fake->lastRequest->approvalTopics);
    }

    // ---- isolamento ----------------------------------------------------------------

    public function test_config_da_conta_ancora_nao_toca_outra_conta(): void
    {
        $accountB = Account::create(['name' => 'B']);
        AutoReplySetting::create(['account_id' => $accountB->id, 'ai_confidence_threshold' => 0.75]);

        Livewire::test(Configuracoes::class)
            ->set('ai_confidence_threshold', 0.90)
            ->call('saveAi');

        $this->assertEqualsWithDelta(0.90, (float) $this->settings()->ai_confidence_threshold, 0.001);
        $this->assertEqualsWithDelta(0.75, (float) AutoReplySetting::where('account_id', $accountB->id)->value('ai_confidence_threshold'), 0.001);
    }
}

/** Driver falso — devolve o resultado configurado e guarda o request (minimizacao). */
class FakeConfigAi implements AiClassifier
{
    public ?AiClassificationRequest $lastRequest = null;

    public function __construct(private ?AiClassification $classifyResult = null)
    {
    }

    public function classify(AiClassificationRequest $request): AiClassification
    {
        $this->lastRequest = $request;

        return $this->classifyResult ?? new AiClassification('', 0.9, null, false, false, 'nenhuma');
    }

    public function answer(AiAnswerRequest $request): AiAnswer
    {
        return AiAnswer::unknown('sem_mock');
    }
}
