<?php

namespace Tests\Feature;

use App\Ai\AiClassification;
use App\Ai\AiClassificationRequest;
use App\Contracts\AiClassifier;
use App\Jobs\ClassifyWithAi;
use App\Jobs\ProcessIncomingWhatsappMessage;
use App\Models\AiDecision;
use App\Models\Account;
use App\Models\AutoReplyLog;
use App\Models\AutoReplyRule;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\IncomingMessage;
use App\Whatsapp\Secrets\SecretVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Camada 3 (IA) Fatia 1 — FALLBACK. Driver MOCKADO (nunca API real), HTTP mockado
 * (nunca envio real). A IA so entra quando nada casou; toda resposta passa pelo Sender.
 */
class AiFallbackTest extends TestCase
{
    use RefreshDatabase;

    private const JID = '5541999990000@s.whatsapp.net';
    private Account $account;
    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 6, 29, 10, 0, 0, 'America/Sao_Paulo'));
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

    /** Regra candidata (com IA casa parecidas ligada) que NAO casa deterministicamente "hora". */
    private function ruleHoras(string $resposta = 'Sao 10h.'): AutoReplyRule
    {
        $rule = AutoReplyRule::create([
            'account_id' => $this->account->id, 'match_type' => 'contains', 'match_value' => 'que horas sao',
            'response_text' => $resposta, 'enabled' => true, 'ai_match_enabled' => true,
        ]);
        $rule->triggers()->create(['match_type' => 'contains', 'match_value' => 'que horas sao']);
        $rule->responses()->create(['response_text' => $resposta]);
        $rule->aiExamples()->create(['phrase' => 'me fala a hora ai']);

        return $rule->fresh();
    }

    private function bindAi(AiClassification $result): FakeAiClassifier
    {
        $fake = new FakeAiClassifier($result);
        $this->app->instance(AiClassifier::class, $fake);

        return $fake;
    }

    private function incoming(string $texto, string $id = 'IM1', ?string $jid = null): IncomingMessage
    {
        return IncomingMessage::create([
            'account_id' => $this->account->id, 'channel_id' => $this->channel->id,
            'instance' => $this->channel->instance, 'evolution_message_id' => $id,
            'remote_jid' => $jid ?: self::JID, 'from_me' => false, 'type' => 'conversation',
            'text' => $texto, 'raw_payload' => ['x' => 1], 'received_at' => now(),
        ]);
    }

    private function runJob(int $incomingId): FakeAiClassifier
    {
        $fake = $this->app->make(AiClassifier::class);
        (new ClassifyWithAi($incomingId))->handle(
            $fake,
            app(\App\Whatsapp\AutoReply\AntiBanGuard::class),
            app(\App\Whatsapp\AutoReply\RuleMatcher::class),
            app(SecretVault::class),
        );

        return $fake;
    }

    private function receber(string $texto, string $id = 'W1'): void
    {
        (new ProcessIncomingWhatsappMessage([
            'event' => 'messages.upsert', 'instance' => 'fabio-pessoal',
            'data' => [
                'key' => ['id' => $id, 'fromMe' => false, 'remoteJid' => self::JID],
                'pushName' => 'Cliente', 'messageType' => 'conversation',
                'message' => ['conversation' => $texto], 'messageTimestamp' => 1782699162,
            ],
        ]))->handle(
            app(\App\Contracts\WhatsappGateway::class),
            app(\App\Whatsapp\AutoReply\RuleMatcher::class),
            app(\App\Whatsapp\AutoReply\AntiBanGuard::class),
        );
    }

    // ---- respostas / escalonamentos ----------------------------------------

    public function test_acima_do_limiar_responde_com_resposta_da_regra(): void
    {
        $rule = $this->ruleHoras('Sao 10h da manha.');
        $this->bindAi(new AiClassification('horario', 0.9, $rule->id, true, false, 'ok'));

        $this->receber('me fala a hora ai', 'W1');

        Http::assertSent(fn ($r) => $r['text'] === 'Sao 10h da manha.');
        $this->assertDatabaseHas('ai_decisions', ['remote_jid' => self::JID, 'acao' => 'respondeu', 'matched_rule_id' => $rule->id]);
    }

    public function test_abaixo_do_limiar_escala(): void
    {
        $rule = $this->ruleHoras();
        $this->bindAi(new AiClassification('horario', 0.50, $rule->id, true, false, 'incerto'));

        $im = $this->incoming('nao sei o que quero');
        $this->runJob($im->id);

        $this->assertDatabaseHas('ai_decisions', ['incoming_message_id' => $im->id, 'acao' => 'escalou', 'motivo' => 'baixa_confianca']);
        Http::assertNothingSent();
    }

    public function test_tema_de_aprovacao_escala(): void
    {
        $rule = $this->ruleHoras();
        // Confianca alta, mas o modelo sinalizou tema sensivel (ex.: pagamento).
        $this->bindAi(new AiClassification('pagamento', 0.95, $rule->id, true, true, 'fala de pix'));

        $im = $this->incoming('me manda o pix pra eu pagar');
        $this->runJob($im->id);

        $this->assertDatabaseHas('ai_decisions', ['incoming_message_id' => $im->id, 'acao' => 'escalou', 'motivo' => 'tema_aprovacao']);
        Http::assertNothingSent();
    }

    public function test_regra_com_senha_nunca_auto_envia_e_segredo_nao_vai_pro_modelo(): void
    {
        app(SecretVault::class)->put($this->account->id, 'wifi', 'segredoDoWifi123');
        $rule = $this->ruleHoras('A senha do wifi e {senha:wifi}');

        $fake = $this->bindAi(new AiClassification('wifi', 0.99, $rule->id, true, false, 'ok'));

        $im = $this->incoming('qual a senha do wifi mesmo');
        $this->runJob($im->id);

        // Nunca auto-envia resposta com segredo -> escala.
        $this->assertDatabaseHas('ai_decisions', ['incoming_message_id' => $im->id, 'acao' => 'escalou', 'motivo' => 'contem_senha']);
        Http::assertNothingSent();

        // MINIMIZACAO: o valor do segredo NUNCA vai pro modelo (nem a resposta da regra).
        $enviadoAoModelo = json_encode([$fake->lastRequest->message, $fake->lastRequest->candidates], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('segredoDoWifi123', $enviadoAoModelo);
        $this->assertStringNotContainsString('{senha:wifi}', $enviadoAoModelo);
    }

    public function test_modo_aprovacao_do_contato_escala(): void
    {
        Contact::where('remote_jid', self::JID)->update(['ai_mode' => 'aprovacao']);
        $rule = $this->ruleHoras();
        $this->bindAi(new AiClassification('horario', 0.95, $rule->id, true, false, 'ok'));

        $im = $this->incoming('que horas tem ai');
        $this->runJob($im->id);

        $this->assertDatabaseHas('ai_decisions', ['incoming_message_id' => $im->id, 'acao' => 'escalou', 'motivo' => 'modo_aprovacao']);
        Http::assertNothingSent();
    }

    public function test_erro_ou_cota_silencia_sem_excecao(): void
    {
        $this->ruleHoras();
        $this->bindAi(AiClassification::unknown('ia_cota', 'gemini-2.5-flash-lite'));

        $im = $this->incoming('me fala a hora ai');
        $this->runJob($im->id); // nao deve lancar

        $this->assertDatabaseHas('ai_decisions', ['incoming_message_id' => $im->id, 'acao' => 'silenciou', 'motivo' => 'ia_cota']);
        Http::assertNothingSent();
    }

    public function test_modelo_recomenda_nao_responder_silencia(): void
    {
        $rule = $this->ruleHoras();
        $this->bindAi(new AiClassification('', 0.9, $rule->id, false, false, 'nao aplicavel'));

        $im = $this->incoming('bla bla');
        $this->runJob($im->id);

        $this->assertDatabaseHas('ai_decisions', ['incoming_message_id' => $im->id, 'acao' => 'silenciou', 'motivo' => 'modelo_nao_responde']);
        Http::assertNothingSent();
    }

    public function test_sem_regra_escolhida_silencia(): void
    {
        $this->ruleHoras();
        $this->bindAi(new AiClassification('', 0.9, null, false, false, 'nenhuma'));

        $im = $this->incoming('assunto aleatorio');
        $this->runJob($im->id);

        $this->assertDatabaseHas('ai_decisions', ['incoming_message_id' => $im->id, 'acao' => 'silenciou', 'motivo' => 'sem_regra']);
        Http::assertNothingSent();
    }

    // ---- pre-checagens: o driver NEM e chamado --------------------------------

    public function test_ia_off_global_nao_chama_driver(): void
    {
        AutoReplySetting::where('account_id', $this->account->id)->update(['ai_enabled' => false]);
        $this->ruleHoras();
        $fake = $this->bindAi(new AiClassification('x', 0.9, 1, true, false, 'ok'));

        $im = $this->incoming('me fala a hora ai');
        $this->runJob($im->id);

        $this->assertSame(0, $fake->calls);
        $this->assertDatabaseCount('ai_decisions', 0);
    }

    public function test_ia_off_no_contato_nao_chama_driver(): void
    {
        Contact::where('remote_jid', self::JID)->update(['ai_enabled' => false]);
        $this->ruleHoras();
        $fake = $this->bindAi(new AiClassification('x', 0.9, 1, true, false, 'ok'));

        $im = $this->incoming('me fala a hora ai');
        $this->runJob($im->id);

        $this->assertSame(0, $fake->calls);
    }

    public function test_modo_rules_only_nao_chama_driver(): void
    {
        Contact::where('remote_jid', self::JID)->update(['ai_mode' => 'rules_only']);
        $this->ruleHoras();
        $fake = $this->bindAi(new AiClassification('x', 0.9, 1, true, false, 'ok'));

        $im = $this->incoming('me fala a hora ai');
        $this->runJob($im->id);

        $this->assertSame(0, $fake->calls);
    }

    public function test_grupo_nao_chama_driver(): void
    {
        $grupo = '123456789@g.us';
        Contact::create(['account_id' => $this->account->id, 'remote_jid' => $grupo, 'auto_reply_mode' => 'on', 'ai_enabled' => true]);
        $this->ruleHoras();
        $fake = $this->bindAi(new AiClassification('x', 0.9, 1, true, false, 'ok'));

        $im = $this->incoming('me fala a hora ai', 'G1', $grupo);
        $this->runJob($im->id);

        $this->assertSame(0, $fake->calls);
    }

    public function test_contato_off_nao_chama_driver(): void
    {
        Contact::where('remote_jid', self::JID)->update(['auto_reply_mode' => 'off']);
        $this->ruleHoras();
        $fake = $this->bindAi(new AiClassification('x', 0.9, 1, true, false, 'ok'));

        $im = $this->incoming('me fala a hora ai');
        $this->runJob($im->id);

        $this->assertSame(0, $fake->calls);
    }

    public function test_sem_candidatas_nao_chama_driver(): void
    {
        // Regra existe mas SEM "IA casa parecidas" ligada -> nao e candidata.
        $rule = AutoReplyRule::create(['account_id' => $this->account->id, 'match_type' => 'contains', 'match_value' => 'que horas sao', 'response_text' => 'x', 'enabled' => true, 'ai_match_enabled' => false]);
        $rule->triggers()->create(['match_type' => 'contains', 'match_value' => 'que horas sao']);
        $rule->responses()->create(['response_text' => 'x']);
        $fake = $this->bindAi(new AiClassification('x', 0.9, $rule->id, true, false, 'ok'));

        $im = $this->incoming('me fala a hora ai');
        $this->runJob($im->id);

        $this->assertSame(0, $fake->calls);
    }

    // ---- fallback de verdade: nao rouba de regra/fluxo ------------------------

    public function test_regra_deterministica_casa_ia_nao_e_acionada(): void
    {
        // Regra que CASA "menu" deterministicamente (e tambem tem IA ligada).
        $rule = AutoReplyRule::create(['account_id' => $this->account->id, 'match_type' => 'contains', 'match_value' => 'menu', 'response_text' => 'RESPOSTA DETERMINISTICA', 'enabled' => true, 'ai_match_enabled' => true]);
        $rule->triggers()->create(['match_type' => 'contains', 'match_value' => 'menu']);
        $rule->responses()->create(['response_text' => 'RESPOSTA DETERMINISTICA']);
        $fake = $this->bindAi(new AiClassification('x', 0.99, $rule->id, true, false, 'ok'));

        $this->receber('menu', 'W1');

        Http::assertSent(fn ($r) => $r['text'] === 'RESPOSTA DETERMINISTICA');
        $this->assertSame(0, $fake->calls); // IA nem foi consultada
        $this->assertDatabaseCount('ai_decisions', 0);
    }

    public function test_sessao_de_fluxo_ativa_ia_nao_entra(): void
    {
        $flow = Flow::create(['account_id' => $this->account->id, 'name' => 'F', 'enabled' => true, 'timeout_seconds' => 600]);
        $flow->triggers()->create(['match_type' => 'contains', 'match_value' => 'menu']);
        $root = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'menu', 'message' => 'RAIZ: 1 - Suporte']);
        $fim = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'final', 'message' => 'FINAL']);
        $root->options()->create(['input' => '1', 'label' => '1 - Suporte', 'next_node_id' => $fim->id]);
        $flow->update(['root_node_id' => $root->id]);
        $this->ruleHoras();
        $fake = $this->bindAi(new AiClassification('x', 0.99, 1, true, false, 'ok'));

        $this->receber('menu', 'F1');       // inicia o fluxo (sessao ativa)
        $this->receber('qualquer coisa', 'F2'); // dentro da sessao -> fluxo, nunca IA

        $this->assertSame(0, $fake->calls);
    }

    // ---- freios + idempotencia ------------------------------------------------

    public function test_freios_seguram_resposta_da_ia_fora_da_janela(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 29, 22, 0, 0, 'America/Sao_Paulo')); // fora da janela
        $rule = $this->ruleHoras('Sao 22h.');
        $this->bindAi(new AiClassification('horario', 0.95, $rule->id, true, false, 'ok'));

        $im = $this->incoming('me fala a hora ai');
        $this->runJob($im->id);

        // A IA decidiu responder, mas o Sender barrou pelo freio de janela.
        $this->assertDatabaseHas('ai_decisions', ['incoming_message_id' => $im->id, 'acao' => 'respondeu']);
        $this->assertDatabaseHas('auto_reply_logs', ['incoming_message_id' => $im->id, 'status' => 'blocked', 'motivo' => 'fora_da_janela']);
        Http::assertNothingSent();
    }

    public function test_uma_decisao_por_mensagem_idempotente(): void
    {
        $rule = $this->ruleHoras();
        $this->bindAi(new AiClassification('horario', 0.95, $rule->id, true, false, 'ok'));

        $im = $this->incoming('me fala a hora ai');
        $this->runJob($im->id);
        $this->runJob($im->id); // 2a vez nao cria nova decisao nem reenvia

        $this->assertSame(1, AiDecision::where('incoming_message_id', $im->id)->count());
        Http::assertSentCount(1);
    }
}

/**
 * Classificador FALSO pros testes — nunca toca na API. Guarda o request pra validar
 * a minimizacao (nada de segredo saindo).
 */
class FakeAiClassifier implements AiClassifier
{
    public int $calls = 0;
    public ?AiClassificationRequest $lastRequest = null;

    public function __construct(private AiClassification $result)
    {
    }

    public function classify(AiClassificationRequest $request): AiClassification
    {
        $this->calls++;
        $this->lastRequest = $request;

        return $this->result;
    }
}
