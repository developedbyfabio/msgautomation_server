<?php

namespace App\Ai\Drivers;

use App\Ai\AiClassification;
use App\Ai\AiClassificationRequest;
use App\Contracts\AiClassifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Driver Gemini (free tier, Flash-Lite) do classificador de intencao.
 *
 * SEGURANCA/ROBUSTEZ:
 *  - CLASSIFICA, nao gera resposta. Prompt de sistema TRAVADO.
 *  - Chave so no .env (config services.gemini.api_key). Vazia -> unknown('sem_chave').
 *  - 429/5xx/timeout -> retry com BACKOFF exponencial; esgotou -> unknown (silencia).
 *  - Cota diaria (config daily_cap) contada por dia (Sao Paulo) -> unknown('ia_cota').
 *  - JSON forcado (responseMimeType + responseSchema). Parse DEFENSIVO: qualquer coisa
 *    fora do formato -> unknown('ia_resposta_invalida'). NUNCA lanca, NUNCA "chuta".
 *  - MINIMIZACAO: pro modelo vai so a mensagem + gatilhos/exemplos das candidatas.
 */
class GeminiDriver implements AiClassifier
{
    private const SYSTEM_PROMPT = <<<'TXT'
Voce e um CLASSIFICADOR DE INTENCAO de um atendimento por mensagem. Voce NAO conversa,
NAO responde como pessoa, NAO inventa e NAO gera texto de resposta. Sua unica tarefa e
dizer QUAL intencao candidata (pelo rule_id) a mensagem do contato corresponde, ou nenhuma.

Regras:
- Escolha no maximo UMA candidata (matched_rule_id). Se nenhuma servir, matched_rule_id=null
  e should_reply=false.
- confidence e sua certeza de 0 a 1. Se estiver em duvida, use confidence baixa.
- Marque needs_approval=true quando a mensagem tratar de qualquer TEMA SENSIVEL informado
  (ex.: pagamento/PIX/valores, dados bancarios/senhas, compromissos/agendamentos).
- reason: uma frase curta explicando a decisao.
- Responda SOMENTE com JSON valido no schema pedido. Nada de texto fora do JSON.
TXT;

    public function classify(AiClassificationRequest $request): AiClassification
    {
        $key = (string) config('services.gemini.api_key');
        if ($key === '') {
            return AiClassification::unknown('sem_chave');
        }

        $model = (string) config('services.gemini.model', 'gemini-2.5-flash-lite');

        // Cota diaria (conservador): conta a intencao de chamar. Estourou -> silencia.
        if (! $this->withinDailyCap()) {
            return AiClassification::unknown('ia_cota', $model);
        }

        $base = rtrim((string) config('services.gemini.base_url'), '/');
        $url = "{$base}/models/{$model}:generateContent";
        $body = $this->buildBody($request);

        $attempts = max(1, (int) config('services.gemini.max_attempts', 3));
        $timeout = max(1, (int) config('services.gemini.timeout', 12));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $resp = Http::withHeaders(['x-goog-api-key' => $key])
                    ->acceptJson()
                    ->timeout($timeout)
                    ->post($url, $body);
            } catch (\Throwable) {
                // Erro de conexao/timeout -> backoff e tenta de novo.
                $this->backoff($attempt);

                continue;
            }

            // 429 (cota/limite) e 5xx sao transitorios -> backoff e retenta.
            if ($resp->status() === 429 || $resp->serverError()) {
                $this->backoff($attempt);

                continue;
            }

            // 4xx (fora 429): erro nao-retentavel (ex.: chave/modelo invalido) -> silencia.
            if ($resp->failed()) {
                return AiClassification::unknown('ia_erro', $model);
            }

            return $this->parse($resp->json(), $model);
        }

        // Esgotou as tentativas (provavel 429/cota ou instabilidade).
        return AiClassification::unknown('ia_indisponivel', $model);
    }

    /** Backoff exponencial com base configuravel (0 nos testes). */
    private function backoff(int $attempt): void
    {
        $base = max(0, (int) config('services.gemini.retry_sleep_ms', 500));
        if ($base === 0) {
            return;
        }
        usleep($base * (2 ** ($attempt - 1)) * 1000);
    }

    /** Conta 1 chamada/dia (Sao Paulo). true = dentro da cota. */
    private function withinDailyCap(): bool
    {
        $cap = (int) config('services.gemini.daily_cap', 1000);
        if ($cap <= 0) {
            return true; // 0/negativo = sem cota local
        }

        $dia = Carbon::now((string) config('app.display_timezone'))->format('Y-m-d');
        $chave = "gemini:calls:{$dia}";
        $usados = (int) Cache::get($chave, 0);
        if ($usados >= $cap) {
            return false;
        }
        // TTL de ~2 dias cobre a virada do dia sem crescer pra sempre.
        Cache::put($chave, $usados + 1, now()->addDays(2));

        return true;
    }

    /** @return array<string,mixed> */
    private function buildBody(AiClassificationRequest $request): array
    {
        $payload = [
            'message' => $request->message,
            'candidates' => array_map(fn ($c) => [
                'rule_id' => (int) ($c['rule_id'] ?? 0),
                'triggers' => array_values($c['triggers'] ?? []),
                'examples' => array_values($c['examples'] ?? []),
            ], $request->candidates),
            'approval_topics' => array_values($request->approvalTopics),
        ];

        return [
            'systemInstruction' => [
                'parts' => [['text' => self::SYSTEM_PROMPT]],
            ],
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
            ]],
            'generationConfig' => [
                'temperature' => 0,
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'matched_rule_id' => ['type' => 'integer', 'nullable' => true],
                        'intent' => ['type' => 'string'],
                        'confidence' => ['type' => 'number'],
                        'should_reply' => ['type' => 'boolean'],
                        'needs_approval' => ['type' => 'boolean'],
                        'reason' => ['type' => 'string'],
                    ],
                    'required' => ['confidence', 'should_reply', 'needs_approval'],
                ],
            ],
        ];
    }

    /** Parse DEFENSIVO da resposta do Gemini. Qualquer desvio -> unknown. */
    private function parse(mixed $json, string $model): AiClassification
    {
        $text = data_get($json, 'candidates.0.content.parts.0.text');
        if (! is_string($text) || trim($text) === '') {
            return AiClassification::unknown('ia_resposta_invalida', $model);
        }

        $data = json_decode($text, true);
        if (! is_array($data)) {
            return AiClassification::unknown('ia_resposta_invalida', $model);
        }

        $matched = $data['matched_rule_id'] ?? null;
        $matchedId = (is_int($matched) || (is_string($matched) && ctype_digit($matched))) ? (int) $matched : null;

        $confidence = $data['confidence'] ?? null;
        if (! is_numeric($confidence)) {
            return AiClassification::unknown('ia_resposta_invalida', $model);
        }

        return new AiClassification(
            intent: is_string($data['intent'] ?? null) ? $data['intent'] : '',
            confidence: max(0.0, min(1.0, (float) $confidence)),
            matchedRuleId: $matchedId,
            shouldReply: (bool) ($data['should_reply'] ?? false),
            needsApproval: (bool) ($data['needs_approval'] ?? false),
            reason: is_string($data['reason'] ?? null) ? $data['reason'] : '',
            model: $model,
            unknown: false,
        );
    }
}
