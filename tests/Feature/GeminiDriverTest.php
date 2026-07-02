<?php

namespace Tests\Feature;

use App\Ai\AiAnswerRequest;
use App\Ai\AiClassificationRequest;
use App\Ai\Drivers\GeminiDriver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Camada 3 (IA) — driver Gemini. HTTP SEMPRE mockado (nunca chama a API real).
 * Prova: parse do JSON, backoff/retry no 429, 4xx nao-retenta, cota diaria, sem chave.
 * Fatia 2: answer() (modo conhecimento) — parse defensivo e cota compartilhada.
 */
class GeminiDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.gemini.api_key' => 'test-key',
            'services.gemini.model' => 'gemini-2.5-flash-lite',
            'services.gemini.max_attempts' => 3,
            'services.gemini.retry_sleep_ms' => 0, // sem sleep real no teste
            'services.gemini.daily_cap' => 1000,
        ]);
    }

    private function req(): AiClassificationRequest
    {
        return new AiClassificationRequest('me fala a hora ai', [
            ['rule_id' => 7, 'triggers' => ['que horas sao'], 'examples' => ['que hora tem']],
        ], ['pagamento']);
    }

    private function geminiResponse(array $obj, int $status = 200)
    {
        return Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => json_encode($obj)]]],
            ]],
        ], $status);
    }

    public function test_sem_chave_retorna_unknown_sem_chamar_api(): void
    {
        config(['services.gemini.api_key' => '']);
        Http::fake();

        $r = app(GeminiDriver::class)->classify($this->req());

        $this->assertTrue($r->unknown);
        $this->assertSame('sem_chave', $r->reason);
        Http::assertNothingSent();
    }

    public function test_json_valido_vira_dto(): void
    {
        Http::fake(['*' => $this->geminiResponse([
            'matched_rule_id' => 7, 'intent' => 'horario', 'confidence' => 0.88,
            'should_reply' => true, 'needs_approval' => false, 'reason' => 'pediu a hora',
        ])]);

        $r = app(GeminiDriver::class)->classify($this->req());

        $this->assertFalse($r->unknown);
        $this->assertSame(7, $r->matchedRuleId);
        $this->assertSame('horario', $r->intent);
        $this->assertEqualsWithDelta(0.88, $r->confidence, 0.0001);
        $this->assertTrue($r->shouldReply);
        $this->assertFalse($r->needsApproval);
        $this->assertSame('gemini-2.5-flash-lite', $r->model);
    }

    public function test_envia_chave_no_header_e_nunca_na_url(): void
    {
        Http::fake(['*' => $this->geminiResponse([
            'matched_rule_id' => null, 'confidence' => 0.1, 'should_reply' => false, 'needs_approval' => false,
        ])]);

        app(GeminiDriver::class)->classify($this->req());

        Http::assertSent(function ($r) {
            return $r->hasHeader('x-goog-api-key', 'test-key')
                && str_contains($r->url(), 'gemini-2.5-flash-lite:generateContent')
                && ! str_contains($r->url(), 'test-key'); // chave nunca na URL
        });
    }

    public function test_json_malformado_vira_unknown(): void
    {
        Http::fake(['*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'isto nao e json']]]]],
        ], 200)]);

        $r = app(GeminiDriver::class)->classify($this->req());

        $this->assertTrue($r->unknown);
        $this->assertSame('ia_resposta_invalida', $r->reason);
    }

    public function test_confidence_ausente_vira_unknown(): void
    {
        Http::fake(['*' => $this->geminiResponse(['intent' => 'x', 'should_reply' => true])]);

        $r = app(GeminiDriver::class)->classify($this->req());

        $this->assertTrue($r->unknown);
    }

    public function test_429_faz_backoff_e_retenta_ate_esgotar(): void
    {
        Http::fake(['*' => Http::response(['error' => 'quota'], 429)]);

        $r = app(GeminiDriver::class)->classify($this->req());

        $this->assertTrue($r->unknown);
        $this->assertSame('ia_indisponivel', $r->reason);
        Http::assertSentCount(3); // 3 tentativas (backoff entre elas)
    }

    public function test_5xx_retenta(): void
    {
        Http::fake(['*' => Http::response(['error' => 'oops'], 503)]);

        $r = app(GeminiDriver::class)->classify($this->req());

        $this->assertTrue($r->unknown);
        Http::assertSentCount(3);
    }

    public function test_4xx_nao_retenta(): void
    {
        Http::fake(['*' => Http::response(['error' => 'bad'], 400)]);

        $r = app(GeminiDriver::class)->classify($this->req());

        $this->assertTrue($r->unknown);
        $this->assertSame('ia_erro', $r->reason);
        Http::assertSentCount(1); // 4xx (fora 429) nao retenta
    }

    public function test_cota_diaria_estoura_e_silencia(): void
    {
        config(['services.gemini.daily_cap' => 1]);
        Http::fake(['*' => $this->geminiResponse([
            'matched_rule_id' => 7, 'confidence' => 0.9, 'should_reply' => true, 'needs_approval' => false,
        ])]);

        $primeira = app(GeminiDriver::class)->classify($this->req());
        $segunda = app(GeminiDriver::class)->classify($this->req());

        $this->assertFalse($primeira->unknown);
        $this->assertTrue($segunda->unknown);
        $this->assertSame('ia_cota', $segunda->reason);
        Http::assertSentCount(1); // a 2a nem chamou a API
    }

    // ---- Fatia 2: answer() (modo conhecimento) --------------------------------

    private function answerReq(): AiAnswerRequest
    {
        return new AiAnswerRequest('que horas abre?', [
            ['id' => 3, 'title' => 'Horario', 'content' => 'Atendemos das 8h as 18h.'],
        ], ['pagamento']);
    }

    public function test_answer_json_valido_vira_dto(): void
    {
        Http::fake(['*' => $this->geminiResponse([
            'answer' => 'Atendemos das 8h as 18h.', 'grounded' => true, 'confidence' => 0.9,
            'needs_approval' => false, 'source_ids' => [3], 'reason' => 'esta na base',
        ])]);

        $r = app(GeminiDriver::class)->answer($this->answerReq());

        $this->assertFalse($r->unknown);
        $this->assertSame('Atendemos das 8h as 18h.', $r->answer);
        $this->assertTrue($r->grounded);
        $this->assertSame([3], $r->sourceIds);
        $this->assertEqualsWithDelta(0.9, $r->confidence, 0.0001);
        $this->assertFalse($r->needsApproval);
        $this->assertSame('gemini-2.5-flash-lite', $r->model);
    }

    public function test_answer_envia_prompt_travado_e_entradas_no_payload(): void
    {
        Http::fake(['*' => $this->geminiResponse([
            'answer' => '', 'grounded' => false, 'confidence' => 0.1, 'needs_approval' => false,
        ])]);

        app(GeminiDriver::class)->answer($this->answerReq());

        Http::assertSent(function ($r) {
            $body = $r->data();
            $user = data_get($body, 'contents.0.parts.0.text');

            return str_contains((string) data_get($body, 'systemInstruction.parts.0.text'), 'EXCLUSIVAMENTE')
                && str_contains((string) $user, 'Atendemos das 8h as 18h.')
                && data_get($body, 'generationConfig.responseMimeType') === 'application/json';
        });
    }

    public function test_answer_json_malformado_vira_unknown(): void
    {
        Http::fake(['*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'nao e json']]]]],
        ], 200)]);

        $r = app(GeminiDriver::class)->answer($this->answerReq());

        $this->assertTrue($r->unknown);
        $this->assertSame('ia_resposta_invalida', $r->reason);
    }

    public function test_answer_sem_campo_answer_vira_unknown(): void
    {
        Http::fake(['*' => $this->geminiResponse(['grounded' => true, 'confidence' => 0.9])]);

        $r = app(GeminiDriver::class)->answer($this->answerReq());

        $this->assertTrue($r->unknown);
    }

    public function test_answer_sem_chave_retorna_unknown_sem_chamar_api(): void
    {
        config(['services.gemini.api_key' => '']);
        Http::fake();

        $r = app(GeminiDriver::class)->answer($this->answerReq());

        $this->assertTrue($r->unknown);
        $this->assertSame('sem_chave', $r->reason);
        Http::assertNothingSent();
    }

    public function test_cota_diaria_e_compartilhada_entre_classify_e_answer(): void
    {
        config(['services.gemini.daily_cap' => 1]);
        Http::fake(['*' => $this->geminiResponse([
            'matched_rule_id' => null, 'confidence' => 0.1, 'should_reply' => false, 'needs_approval' => false,
        ])]);

        $primeira = app(GeminiDriver::class)->classify($this->req());
        $segunda = app(GeminiDriver::class)->answer($this->answerReq());

        $this->assertFalse($primeira->unknown);
        $this->assertTrue($segunda->unknown);
        $this->assertSame('ia_cota', $segunda->reason);
        Http::assertSentCount(1);
    }
}
