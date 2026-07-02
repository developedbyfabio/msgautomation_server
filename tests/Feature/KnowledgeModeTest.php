<?php

namespace Tests\Feature;

use App\Ai\AiAnswer;
use App\Ai\AiAnswerRequest;
use App\Ai\AiClassification;
use App\Ai\AiClassificationRequest;
use App\Contracts\AiClassifier;
use App\Jobs\ClassifyWithAi;
use App\Jobs\ProcessIncomingWhatsappMessage;
use App\Models\Account;
use App\Models\AiDecision;
use App\Models\AutoReplyRule;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\IncomingMessage;
use App\Models\Knowledge;
use App\Whatsapp\Secrets\SecretVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Camada 3 (IA) Fatia 2 — modo `conhecimento` (base de conhecimento). Driver MOCKADO
 * (nunca API real), HTTP mockado (nunca envio real). Regras duras provadas aqui:
 * high NUNCA vai pro modelo nem e respondido direto; resposta so sai grounded nas
 * entradas fornecidas; {senha:}/valor de segredo nunca vao ao modelo nem sao
 * auto-enviados; toda resposta passa pelo Sender (freios + R2).
 */
class KnowledgeModeTest extends TestCase
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
            'auto_reply_mode' => 'on', 'ai_enabled' => true, 'ai_mode' => 'conhecimento',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function kb(string $title, string $content, string $sensitivity = 'medium', array $contactIds = [], bool $active = true): Knowledge
    {
        $k = Knowledge::create([
            'account_id' => $this->account->id, 'title' => $title, 'content' => $content,
            'sensitivity' => $sensitivity, 'active' => $active,
        ]);
        if ($contactIds !== []) {
            $k->contacts()->sync($contactIds);
        }

        return $k;
    }

    /** Resposta grounded padrao (acima do limiar) fundamentada nas entradas dadas. */
    private function groundedAnswer(array $sourceIds, string $answer = 'Atendemos das 8h as 18h.', float $confidence = 0.9): AiAnswer
    {
        return new AiAnswer($answer, true, $confidence, false, $sourceIds, 'ok', 'gemini-2.5-flash-lite');
    }

    private function bindAi(?AiClassification $classify = null, ?AiAnswer $answer = null): FakeKnowledgeAi
    {
        $fake = new FakeKnowledgeAi($classify, $answer);
        $this->app->instance(AiClassifier::class, $fake);

        return $fake;
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

    private function runJob(int $incomingId): FakeKnowledgeAi
    {
        $fake = $this->app->make(AiClassifier::class);
        (new ClassifyWithAi($incomingId))->handle(
            $fake,
            app(\App\Whatsapp\AutoReply\AntiBanGuard::class),
            app(\App\Whatsapp\AutoReply\RuleMatcher::class),
            app(SecretVault::class),
            app(\App\Whatsapp\AutoReply\RuleResponder::class),
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

    // ---- resposta pela base -------------------------------------------------

    public function test_grounded_acima_do_limiar_responde_pela_base(): void
    {
        $k = $this->kb('Horario', 'Atendemos de segunda a sexta, das 8h as 18h.', 'low');
        $this->bindAi(answer: $this->groundedAnswer([$k->id]));

        $im = $this->incoming('vcs abrem que horas?');
        $fake = $this->runJob($im->id);

        // Sem regra candidata -> nem gasta a classificacao; vai direto pra base.
        $this->assertSame(0, $fake->classifyCalls);
        $this->assertSame(1, $fake->answerCalls);

        Http::assertSent(fn ($r) => $r['text'] === 'Atendemos das 8h as 18h.');
        $this->assertDatabaseHas('ai_decisions', [
            'incoming_message_id' => $im->id, 'acao' => 'respondeu', 'origem' => 'base',
            'resposta_resumo' => 'Atendemos das 8h as 18h.',
        ]);
        $this->assertSame([$k->id], AiDecision::where('incoming_message_id', $im->id)->first()->knowledge_ids);
        // O ENVIO segue logando em auto_reply_logs (via Sender), como qualquer resposta.
        $this->assertDatabaseHas('auto_reply_logs', ['incoming_message_id' => $im->id, 'status' => 'sent']);
    }

    public function test_placeholder_nome_e_renderizado_no_envio(): void
    {
        $k = $this->kb('Horario', 'Atendemos das 8h as 18h.', 'low');
        $this->bindAi(answer: $this->groundedAnswer([$k->id], '{nome}, atendemos das 8h as 18h.'));

        $im = $this->incoming('que horas abre?');
        $this->runJob($im->id);

        // {nome} resolvido LOCALMENTE no envio (nunca antes do modelo).
        Http::assertSent(fn ($r) => $r['text'] === 'Cliente, atendemos das 8h as 18h.');
    }

    // ---- sensibilidade high -------------------------------------------------

    public function test_high_nunca_vai_pro_modelo_e_nunca_e_respondido(): void
    {
        $low = $this->kb('Horario', 'Atendemos das 8h as 18h.', 'low');
        $this->kb('Dados bancarios', 'CONTEUDO-SECRETO-BANCO agencia 1234 conta 56789', 'high');

        // Modelo nao encontra fundamento nas low/medium -> e o conteudo high existe.
        $this->bindAi(answer: new AiAnswer('', false, 0.2, false, [], 'nao achei', 'gemini-2.5-flash-lite'));

        $im = $this->incoming('qual a conta pra deposito?');
        $fake = $this->runJob($im->id);

        // MINIMIZACAO: o conteudo high NUNCA aparece no payload enviado ao modelo.
        $enviado = json_encode($fake->lastAnswerRequest->entries, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('CONTEUDO-SECRETO-BANCO', $enviado);
        $this->assertStringContainsString('Atendemos das 8h as 18h.', $enviado);

        // E NUNCA e respondido direto -> escala pra revisao humana.
        $this->assertDatabaseHas('ai_decisions', ['incoming_message_id' => $im->id, 'acao' => 'escalou', 'motivo' => 'conteudo_high', 'origem' => 'base']);
        Http::assertNothingSent();
    }

    public function test_so_entradas_high_escala_sem_chamar_o_modelo(): void
    {
        $this->kb('Dados bancarios', 'agencia 1234 conta 56789', 'high');
        $fake = $this->bindAi();

        $im = $this->incoming('qual a conta pra deposito?');
        $this->runJob($im->id);

        // Nao ha o que mandar pro modelo (so high) -> escala direto, sem gastar API.
        $this->assertSame(0, $fake->answerCalls);
        $this->assertDatabaseHas('ai_decisions', ['incoming_message_id' => $im->id, 'acao' => 'escalou', 'motivo' => 'conteudo_high', 'origem' => 'base']);
        Http::assertNothingSent();
    }

    public function test_high_nao_bloqueia_resposta_fundamentada_em_low(): void
    {
        $low = $this->kb('Horario', 'Atendemos das 8h as 18h.', 'low');
        $this->kb('Dados bancarios', 'agencia 1234', 'high');
        $this->bindAi(answer: $this->groundedAnswer([$low->id]));

        $im = $this->incoming('que horas abre?');
        $this->runJob($im->id);

        // Resposta fundamentada SO na entrada low (o modelo nunca viu a high) -> envia.
        Http::assertSent(fn ($r) => $r['text'] === 'Atendemos das 8h as 18h.');
        $this->assertDatabaseHas('ai_decisions', ['incoming_message_id' => $im->id, 'acao' => 'respondeu', 'origem' => 'base']);
    }

    // ---- permissao por contato ----------------------------------------------

    public function test_entrada_sem_permissao_fica_fora_das_candidatas(): void
    {
        $outro = Contact::create(['account_id' => $this->account->id, 'remote_jid' => '5541888880000@s.whatsapp.net']);
        $permitida = $this->kb('Horario', 'Atendemos das 8h as 18h.', 'low');
        $this->kb('Restrita', 'CONTEUDO-RESTRITO-OUTRO-CONTATO', 'low', [$outro->id]);

        $this->bindAi(answer: new AiAnswer('', false, 0.2, false, [], 'nao achei', null));

        $im = $this->incoming('qual o preco?');
        $fake = $this->runJob($im->id);

        $enviado = json_encode($fake->lastAnswerRequest->entries, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('CONTEUDO-RESTRITO-OUTRO-CONTATO', $enviado);
        $this->assertStringContainsString('Atendemos das 8h as 18h.', $enviado);
    }

    public function test_entrada_com_permissao_do_contato_entra_nas_candidatas(): void
    {
        $k = $this->kb('Exclusiva', 'Conteudo exclusivo deste contato.', 'low', [$this->contact->id]);
        $this->bindAi(answer: $this->groundedAnswer([$k->id], 'Conteudo exclusivo deste contato.'));

        $im = $this->incoming('me fala aquilo');
        $fake = $this->runJob($im->id);

        $this->assertSame(1, $fake->answerCalls);
        Http::assertSent(fn ($r) => $r['text'] === 'Conteudo exclusivo deste contato.');
    }

    public function test_entrada_inativa_fica_fora_das_candidatas(): void
    {
        $this->kb('Desativada', 'CONTEUDO-DESATIVADO', 'low', active: false);
        $fake = $this->bindAi();

        $im = $this->incoming('qualquer coisa');
        $this->runJob($im->id);

        // Nada candidato -> base nem e consultada (sem chamada, sem log).
        $this->assertSame(0, $fake->answerCalls);
        $this->assertDatabaseCount('ai_decisions', 0);
    }

    // ---- "nao sei" / limiar / temas -----------------------------------------

    public function test_sem_grounding_silencia_e_loga(): void
    {
        $this->kb('Horario', 'Atendemos das 8h as 18h.', 'low');
        // Modelo devolveu texto mas SEM grounding -> "nao sei" -> nunca envia.
        $this->bindAi(answer: new AiAnswer('Acho que abre as 9h.', false, 0.9, false, [], 'chute', null));

        $im = $this->incoming('que horas abre?');
        $this->runJob($im->id);

        $this->assertDatabaseHas('ai_decisions', ['incoming_message_id' => $im->id, 'acao' => 'silenciou', 'motivo' => 'sem_grounding', 'origem' => 'base']);
        Http::assertNothingSent();
    }

    public function test_source_ids_fora_das_candidatas_e_tratado_como_sem_grounding(): void
    {
        $this->kb('Horario', 'Atendemos das 8h as 18h.', 'low');
        // Modelo "grounded" mas apontando id que NAO e candidato -> nunca confia.
        $this->bindAi(answer: $this->groundedAnswer([999999], 'Resposta suspeita.'));

        $im = $this->incoming('que horas abre?');
        $this->runJob($im->id);

        $this->assertDatabaseHas('ai_decisions', ['incoming_message_id' => $im->id, 'acao' => 'silenciou', 'motivo' => 'sem_grounding']);
        Http::assertNothingSent();
    }

    public function test_json_malformado_ou_erro_silencia(): void
    {
        $this->kb('Horario', 'Atendemos das 8h as 18h.', 'low');
        $this->bindAi(answer: AiAnswer::unknown('ia_resposta_invalida', 'gemini-2.5-flash-lite'));

        $im = $this->incoming('que horas abre?');
        $this->runJob($im->id);

        $this->assertDatabaseHas('ai_decisions', ['incoming_message_id' => $im->id, 'acao' => 'silenciou', 'motivo' => 'ia_resposta_invalida', 'origem' => 'base']);
        Http::assertNothingSent();
    }

    public function test_abaixo_do_limiar_escala_e_nao_envia(): void
    {
        $k = $this->kb('Horario', 'Atendemos das 8h as 18h.', 'low');
        $this->bindAi(answer: $this->groundedAnswer([$k->id], confidence: 0.5));

        $im = $this->incoming('que horas abre?');
        $this->runJob($im->id);

        $this->assertDatabaseHas('ai_decisions', ['incoming_message_id' => $im->id, 'acao' => 'escalou', 'motivo' => 'baixa_confianca', 'origem' => 'base']);
        Http::assertNothingSent();
    }

    public function test_tema_de_aprovacao_escala_e_nao_envia(): void
    {
        $k = $this->kb('Precos', 'O produto custa mil reais.', 'low');
        $this->bindAi(answer: new AiAnswer('O produto custa mil reais.', true, 0.95, true, [$k->id], 'fala de valores', null));

        $im = $this->incoming('quanto custa? posso pagar por pix?');
        $this->runJob($im->id);

        $this->assertDatabaseHas('ai_decisions', ['incoming_message_id' => $im->id, 'acao' => 'escalou', 'motivo' => 'tema_aprovacao', 'origem' => 'base']);
        Http::assertNothingSent();
    }

    // ---- segredos ({senha:}) ------------------------------------------------

    public function test_senha_no_conteudo_valor_nunca_vai_pro_modelo_e_nunca_auto_envia(): void
    {
        app(SecretVault::class)->put($this->account->id, 'wifi', 'segredoDoWifi123');
        $k = $this->kb('Wifi', 'A senha do wifi e {senha:wifi}', 'medium');
        $this->bindAi(answer: $this->groundedAnswer([$k->id], 'A senha do wifi e {senha:wifi}'));

        $im = $this->incoming('qual a senha do wifi?');
        $fake = $this->runJob($im->id);

        // MINIMIZACAO: o VALOR nunca vai; o placeholder vai INTACTO (nunca expandido).
        $enviado = json_encode([$fake->lastAnswerRequest->message, $fake->lastAnswerRequest->entries], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('segredoDoWifi123', $enviado);
        $this->assertStringContainsString('{senha:wifi}', $enviado);

        // Guarda dura: a IA NUNCA auto-envia resposta com segredo -> escala.
        $this->assertDatabaseHas('ai_decisions', ['incoming_message_id' => $im->id, 'acao' => 'escalou', 'motivo' => 'contem_senha', 'origem' => 'base']);
        Http::assertNothingSent();

        // O log da decisao guarda o resumo REDIGIDO (nunca o placeholder cru/valor).
        $dec = AiDecision::where('incoming_message_id', $im->id)->first();
        $this->assertStringNotContainsString('segredoDoWifi123', (string) $dec->resposta_resumo);
        $this->assertStringNotContainsString('{senha:wifi}', (string) $dec->resposta_resumo);
    }

    public function test_entrada_usada_com_senha_escala_mesmo_se_o_modelo_omitir_o_placeholder(): void
    {
        app(SecretVault::class)->put($this->account->id, 'wifi', 'segredoDoWifi123');
        $k = $this->kb('Wifi', 'A senha do wifi e {senha:wifi}', 'medium');
        // Modelo reescreveu a resposta SEM o placeholder — a guarda olha a entrada usada.
        $this->bindAi(answer: $this->groundedAnswer([$k->id], 'A senha e a de sempre, te passo ja.'));

        $im = $this->incoming('qual a senha do wifi?');
        $this->runJob($im->id);

        $this->assertDatabaseHas('ai_decisions', ['incoming_message_id' => $im->id, 'acao' => 'escalou', 'motivo' => 'contem_senha']);
        Http::assertNothingSent();
    }

    // ---- integracao com o degrau 1 (casar regra por IA) ----------------------

    public function test_regra_casada_por_ia_vence_e_base_nao_e_consultada(): void
    {
        $rule = AutoReplyRule::create([
            'account_id' => $this->account->id, 'match_type' => 'contains', 'match_value' => 'que horas sao',
            'response_text' => 'Sao 10h.', 'enabled' => true, 'ai_match_enabled' => true,
        ]);
        $rule->triggers()->create(['match_type' => 'contains', 'match_value' => 'que horas sao']);
        $rule->responses()->create(['response_text' => 'Sao 10h.']);
        $this->kb('Horario', 'Atendemos das 8h as 18h.', 'low');

        $fake = $this->bindAi(new AiClassification('horario', 0.9, $rule->id, true, false, 'ok'));

        $im = $this->incoming('me fala a hora ai');
        $this->runJob($im->id);

        // Regra casou por IA -> responde a resposta DA REGRA; base nem entra.
        $this->assertSame(1, $fake->classifyCalls);
        $this->assertSame(0, $fake->answerCalls);
        Http::assertSent(fn ($r) => $r['text'] === 'Sao 10h.');
        $this->assertDatabaseHas('ai_decisions', ['incoming_message_id' => $im->id, 'acao' => 'respondeu', 'origem' => 'regra', 'matched_rule_id' => $rule->id]);
    }

    public function test_sem_regra_casada_cai_pra_base(): void
    {
        $rule = AutoReplyRule::create([
            'account_id' => $this->account->id, 'match_type' => 'contains', 'match_value' => 'que horas sao',
            'response_text' => 'Sao 10h.', 'enabled' => true, 'ai_match_enabled' => true,
        ]);
        $rule->triggers()->create(['match_type' => 'contains', 'match_value' => 'que horas sao']);
        $rule->responses()->create(['response_text' => 'Sao 10h.']);
        $k = $this->kb('Horario', 'Atendemos das 8h as 18h.', 'low');

        // Classificacao: nenhuma regra casou -> degrau 2 responde pela base.
        $fake = $this->bindAi(
            new AiClassification('', 0.9, null, false, false, 'nenhuma'),
            $this->groundedAnswer([$k->id]),
        );

        $im = $this->incoming('vcs funcionam ate que horas?');
        $this->runJob($im->id);

        $this->assertSame(1, $fake->classifyCalls);
        $this->assertSame(1, $fake->answerCalls);
        Http::assertSent(fn ($r) => $r['text'] === 'Atendemos das 8h as 18h.');
        $this->assertDatabaseHas('ai_decisions', ['incoming_message_id' => $im->id, 'acao' => 'respondeu', 'origem' => 'base']);
        // Uma unica decisao por mensagem (o degrau 1 nao loga quando cai pro 2).
        $this->assertSame(1, AiDecision::where('incoming_message_id', $im->id)->count());
    }

    public function test_tema_sensivel_na_classificacao_escala_sem_gastar_segunda_chamada(): void
    {
        $rule = AutoReplyRule::create([
            'account_id' => $this->account->id, 'match_type' => 'contains', 'match_value' => 'que horas sao',
            'response_text' => 'Sao 10h.', 'enabled' => true, 'ai_match_enabled' => true,
        ]);
        $rule->triggers()->create(['match_type' => 'contains', 'match_value' => 'que horas sao']);
        $rule->responses()->create(['response_text' => 'Sao 10h.']);
        $this->kb('Precos', 'O produto custa mil reais.', 'low');

        // Sem regra casada, mas o modelo marcou tema sensivel (pix/pagamento).
        $fake = $this->bindAi(new AiClassification('pagamento', 0.9, null, false, true, 'fala de pix'));

        $im = $this->incoming('me passa o pix');
        $this->runJob($im->id);

        $this->assertSame(0, $fake->answerCalls); // poupa a 2a chamada
        $this->assertDatabaseHas('ai_decisions', ['incoming_message_id' => $im->id, 'acao' => 'escalou', 'motivo' => 'tema_aprovacao', 'origem' => 'base']);
        Http::assertNothingSent();
    }

    public function test_erro_na_classificacao_nao_tenta_a_base(): void
    {
        $rule = AutoReplyRule::create([
            'account_id' => $this->account->id, 'match_type' => 'contains', 'match_value' => 'que horas sao',
            'response_text' => 'Sao 10h.', 'enabled' => true, 'ai_match_enabled' => true,
        ]);
        $rule->triggers()->create(['match_type' => 'contains', 'match_value' => 'que horas sao']);
        $rule->responses()->create(['response_text' => 'Sao 10h.']);
        $this->kb('Horario', 'Atendemos das 8h as 18h.', 'low');

        $fake = $this->bindAi(AiClassification::unknown('ia_cota', 'gemini-2.5-flash-lite'));

        $im = $this->incoming('vcs funcionam ate que horas?');
        $this->runJob($im->id);

        // API com problema (cota/erro) -> silencia; nao gasta a 2a chamada.
        $this->assertSame(0, $fake->answerCalls);
        $this->assertDatabaseHas('ai_decisions', ['incoming_message_id' => $im->id, 'acao' => 'silenciou', 'motivo' => 'ia_cota']);
    }

    // ---- modos / guardas: a base NAO e consultada ----------------------------

    public function test_modo_intencao_nao_usa_a_base(): void
    {
        Contact::where('id', $this->contact->id)->update(['ai_mode' => 'intencao']);
        $rule = AutoReplyRule::create([
            'account_id' => $this->account->id, 'match_type' => 'contains', 'match_value' => 'que horas sao',
            'response_text' => 'Sao 10h.', 'enabled' => true, 'ai_match_enabled' => true,
        ]);
        $rule->triggers()->create(['match_type' => 'contains', 'match_value' => 'que horas sao']);
        $rule->responses()->create(['response_text' => 'Sao 10h.']);
        $this->kb('Horario', 'Atendemos das 8h as 18h.', 'low');

        // Nenhuma regra casou -> em intencao NAO cai pra base: silencia (Fatia 1).
        $fake = $this->bindAi(new AiClassification('', 0.9, null, false, false, 'nenhuma'));

        $im = $this->incoming('vcs funcionam ate que horas?');
        $this->runJob($im->id);

        $this->assertSame(0, $fake->answerCalls);
        $this->assertDatabaseHas('ai_decisions', ['incoming_message_id' => $im->id, 'acao' => 'silenciou', 'motivo' => 'sem_regra', 'origem' => 'regra']);
        Http::assertNothingSent();
    }

    public function test_ia_off_global_base_nem_e_consultada(): void
    {
        AutoReplySetting::where('account_id', $this->account->id)->update(['ai_enabled' => false]);
        $this->kb('Horario', 'Atendemos das 8h as 18h.', 'low');
        $fake = $this->bindAi();

        $im = $this->incoming('que horas abre?');
        $this->runJob($im->id);

        $this->assertSame(0, $fake->answerCalls);
        $this->assertDatabaseCount('ai_decisions', 0);
    }

    public function test_ia_off_no_contato_base_nem_e_consultada(): void
    {
        Contact::where('id', $this->contact->id)->update(['ai_enabled' => false]);
        $this->kb('Horario', 'Atendemos das 8h as 18h.', 'low');
        $fake = $this->bindAi();

        $im = $this->incoming('que horas abre?');
        $this->runJob($im->id);

        $this->assertSame(0, $fake->answerCalls);
    }

    public function test_contato_off_base_nem_e_consultada(): void
    {
        Contact::where('id', $this->contact->id)->update(['auto_reply_mode' => 'off']);
        $this->kb('Horario', 'Atendemos das 8h as 18h.', 'low');
        $fake = $this->bindAi();

        $im = $this->incoming('que horas abre?');
        $this->runJob($im->id);

        $this->assertSame(0, $fake->answerCalls);
    }

    public function test_grupo_base_nem_e_consultada(): void
    {
        $grupo = '123456789@g.us';
        Contact::create(['account_id' => $this->account->id, 'remote_jid' => $grupo, 'auto_reply_mode' => 'on', 'ai_enabled' => true, 'ai_mode' => 'conhecimento']);
        $this->kb('Horario', 'Atendemos das 8h as 18h.', 'low');
        $fake = $this->bindAi();

        $im = $this->incoming('que horas abre?', 'G1', $grupo);
        $this->runJob($im->id);

        $this->assertSame(0, $fake->answerCalls);
    }

    public function test_regra_deterministica_casa_ia_e_base_nao_entram(): void
    {
        $rule = AutoReplyRule::create(['account_id' => $this->account->id, 'match_type' => 'contains', 'match_value' => 'menu', 'response_text' => 'RESPOSTA DETERMINISTICA', 'enabled' => true]);
        $rule->triggers()->create(['match_type' => 'contains', 'match_value' => 'menu']);
        $rule->responses()->create(['response_text' => 'RESPOSTA DETERMINISTICA']);
        $this->kb('Horario', 'Atendemos das 8h as 18h.', 'low');
        $fake = $this->bindAi();

        $this->receber('menu', 'W1');

        Http::assertSent(fn ($r) => $r['text'] === 'RESPOSTA DETERMINISTICA');
        $this->assertSame(0, $fake->classifyCalls);
        $this->assertSame(0, $fake->answerCalls);
        $this->assertDatabaseCount('ai_decisions', 0);
    }

    public function test_sessao_de_fluxo_ativa_base_nao_entra(): void
    {
        $flow = \App\Models\Flow::create(['account_id' => $this->account->id, 'name' => 'F', 'enabled' => true, 'timeout_seconds' => 600]);
        $flow->triggers()->create(['match_type' => 'contains', 'match_value' => 'menu']);
        $root = \App\Models\FlowNode::create(['flow_id' => $flow->id, 'kind' => 'menu', 'message' => 'RAIZ: 1 - Suporte']);
        $fim = \App\Models\FlowNode::create(['flow_id' => $flow->id, 'kind' => 'final', 'message' => 'FINAL']);
        $root->options()->create(['input' => '1', 'label' => '1 - Suporte', 'next_node_id' => $fim->id]);
        $flow->update(['root_node_id' => $root->id]);
        $this->kb('Horario', 'Atendemos das 8h as 18h.', 'low');
        $fake = $this->bindAi();

        $this->receber('menu', 'F1');            // inicia o fluxo (sessao ativa)
        $this->receber('qualquer coisa', 'F2');  // dentro da sessao -> fluxo, nunca IA

        $this->assertSame(0, $fake->classifyCalls);
        $this->assertSame(0, $fake->answerCalls);
    }

    // ---- freios + idempotencia ------------------------------------------------

    public function test_freios_seguram_resposta_da_base_fora_da_janela(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 2, 22, 0, 0, 'America/Sao_Paulo')); // fora da janela
        $k = $this->kb('Horario', 'Atendemos das 8h as 18h.', 'low');
        $this->bindAi(answer: $this->groundedAnswer([$k->id]));

        $im = $this->incoming('que horas abre?');
        $this->runJob($im->id);

        // A IA decidiu responder, mas o Sender barrou pelo freio de janela (R2 idem).
        $this->assertDatabaseHas('ai_decisions', ['incoming_message_id' => $im->id, 'acao' => 'respondeu', 'origem' => 'base']);
        $this->assertDatabaseHas('auto_reply_logs', ['incoming_message_id' => $im->id, 'status' => 'blocked', 'motivo' => 'fora_da_janela']);
        Http::assertNothingSent();
    }

    public function test_uma_decisao_por_mensagem_idempotente(): void
    {
        $k = $this->kb('Horario', 'Atendemos das 8h as 18h.', 'low');
        $this->bindAi(answer: $this->groundedAnswer([$k->id]));

        $im = $this->incoming('que horas abre?');
        $this->runJob($im->id);
        $this->runJob($im->id); // 2a vez nao cria nova decisao nem reenvia

        $this->assertSame(1, AiDecision::where('incoming_message_id', $im->id)->count());
        Http::assertSentCount(1);
    }
}

/**
 * Driver FALSO do modo conhecimento — nunca toca na API. Guarda os requests pra
 * validar a minimizacao (high/segredo nunca saem). classify() default = "nenhuma
 * regra casou" (cai pro degrau 2); answer() default = unknown ("nao sei").
 */
class FakeKnowledgeAi implements AiClassifier
{
    public int $classifyCalls = 0;
    public int $answerCalls = 0;
    public ?AiClassificationRequest $lastClassifyRequest = null;
    public ?AiAnswerRequest $lastAnswerRequest = null;

    public function __construct(
        private ?AiClassification $classifyResult = null,
        private ?AiAnswer $answerResult = null,
    ) {
    }

    public function classify(AiClassificationRequest $request): AiClassification
    {
        $this->classifyCalls++;
        $this->lastClassifyRequest = $request;

        return $this->classifyResult ?? new AiClassification('', 0.9, null, false, false, 'nenhuma');
    }

    public function answer(AiAnswerRequest $request): AiAnswer
    {
        $this->answerCalls++;
        $this->lastAnswerRequest = $request;

        return $this->answerResult ?? AiAnswer::unknown('sem_mock');
    }
}
