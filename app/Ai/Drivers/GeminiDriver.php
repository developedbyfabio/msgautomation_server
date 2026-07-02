<?php

namespace App\Ai\Drivers;

use App\Ai\AiAnswer;
use App\Ai\AiAnswerRequest;
use App\Ai\AiClassification;
use App\Ai\AiClassificationRequest;
use App\Contracts\AiClassifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Driver Gemini (free tier, Flash-Lite) do classificador de intencao (Fatia 1) e da
 * resposta fundamentada na base de conhecimento (Fatia 2, modo `conhecimento`).
 *
 * SEGURANCA/ROBUSTEZ:
 *  - classify() CLASSIFICA, nao gera resposta. answer() responde SO com o que esta
 *    no conteudo fornecido da base (grounded) — sem grounding, devolve "nao sei".
 *    Prompts de sistema TRAVADOS nos dois casos.
 *  - Chave so no .env (config services.gemini.api_key). Vazia -> unknown('sem_chave').
 *  - 429/5xx/timeout -> retry com BACKOFF exponencial; esgotou -> unknown (silencia).
 *  - Cota diaria (config daily_cap) contada por dia (Sao Paulo), compartilhada entre
 *    classify/answer -> unknown('ia_cota').
 *  - JSON forcado (responseMimeType + responseSchema). Parse DEFENSIVO: qualquer coisa
 *    fora do formato -> unknown('ia_resposta_invalida'). NUNCA lanca, NUNCA "chuta".
 *  - MINIMIZACAO: pro modelo vai so a mensagem + gatilhos/exemplos (classify) ou as
 *    entradas low/medium permitidas (answer). Placeholders NUNCA expandidos antes.
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

    private const ANSWER_SYSTEM_PROMPT = <<<'TXT'
Voce responde mensagens de um atendimento usando EXCLUSIVAMENTE as entradas da base de
conhecimento fornecidas. Voce NAO inventa, NAO usa conhecimento proprio, NAO especula.

Regras:
- Se as entradas fornecidas respondem a mensagem, escreva uma resposta curta e direta
  em portugues, baseada SO nesse conteudo, e marque grounded=true e source_ids com os
  ids das entradas usadas.
- Se as entradas NAO respondem a mensagem (ou respondem so parcialmente), marque
  grounded=false, answer="" e source_ids=[]. NUNCA complete com conhecimento externo.
- Textos como {senha:nome}, {nome}, {saudacao} sao placeholders do sistema: se aparecerem
  no conteudo usado, COPIE-OS INTACTOS na resposta. Nunca invente o valor deles.
- confidence e sua certeza de 0 a 1 de que a resposta esta correta E fundamentada.
- Marque needs_approval=true quando a mensagem tratar de qualquer TEMA SENSIVEL informado
  (ex.: pagamento/PIX/valores, dados bancarios/senhas, compromissos/agendamentos).
- reason: uma frase curta explicando a decisao.
- Responda SOMENTE com JSON valido no schema pedido. Nada de texto fora do JSON.
TXT;

    public function classify(AiClassificationRequest $request): AiClassification
    {
        $model = (string) config('services.gemini.model', 'gemini-2.5-flash-lite');

        $json = $this->call($this->buildBody($request), $reason);
        if ($json === null) {
            return AiClassification::unknown($reason, $reason === 'sem_chave' ? null : $model);
        }

        return $this->parse($json, $model);
    }

    public function answer(AiAnswerRequest $request): AiAnswer
    {
        $model = (string) config('services.gemini.model', 'gemini-2.5-flash-lite');

        $json = $this->call($this->buildAnswerBody($request), $reason);
        if ($json === null) {
            return AiAnswer::unknown($reason, $reason === 'sem_chave' ? null : $model);
        }

        return $this->parseAnswer($json, $model);
    }

    /**
     * Caminho HTTP COMPARTILHADO (chave, cota, backoff/retry). Retorna o JSON da API
     * ou null com $reason preenchido (sem_chave | ia_cota | ia_erro | ia_indisponivel).
     *
     * @param  array<string,mixed>  $body
     */
    private function call(array $body, ?string &$reason): mixed
    {
        $key = (string) config('services.gemini.api_key');
        if ($key === '') {
            $reason = 'sem_chave';

            return null;
        }

        // Cota diaria (conservador): conta a intencao de chamar. Estourou -> silencia.
        if (! $this->withinDailyCap()) {
            $reason = 'ia_cota';

            return null;
        }

        $model = (string) config('services.gemini.model', 'gemini-2.5-flash-lite');
        $base = rtrim((string) config('services.gemini.base_url'), '/');
        $url = "{$base}/models/{$model}:generateContent";

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
                $reason = 'ia_erro';

                return null;
            }

            return $resp->json();
        }

        // Esgotou as tentativas (provavel 429/cota ou instabilidade).
        $reason = 'ia_indisponivel';

        return null;
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

    /** Conta 1 chamada/dia (Sao Paulo) POR CONTA (MT-0). true = dentro da cota. */
    private function withinDailyCap(): bool
    {
        $cap = (int) config('services.gemini.daily_cap', 1000);
        if ($cap <= 0) {
            return true; // 0/negativo = sem cota local
        }

        // MT-0: cota POR CONTA — uma conta esgotando a franquia nao silencia a IA
        // das outras. O contexto vem do job (ClassifyWithAi seta antes de chamar).
        $accountId = app(\App\Tenancy\AccountContext::class)->id();
        $dia = Carbon::now((string) config('app.display_timezone'))->format('Y-m-d');
        $chave = "ai:{$accountId}:gemini:calls:{$dia}";
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

        return $this->envelope(self::SYSTEM_PROMPT, $payload, [
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
        ]);
    }

    /** @return array<string,mixed> */
    private function buildAnswerBody(AiAnswerRequest $request): array
    {
        // MINIMIZACAO: so entradas low/medium permitidas (quem chama ja filtrou);
        // placeholders intactos — o valor real nunca aparece aqui.
        $payload = [
            'message' => $request->message,
            'knowledge' => array_map(fn ($e) => [
                'id' => (int) ($e['id'] ?? 0),
                'title' => (string) ($e['title'] ?? ''),
                'content' => (string) ($e['content'] ?? ''),
            ], $request->entries),
            'approval_topics' => array_values($request->approvalTopics),
        ];

        return $this->envelope(self::ANSWER_SYSTEM_PROMPT, $payload, [
            'type' => 'object',
            'properties' => [
                'answer' => ['type' => 'string'],
                'grounded' => ['type' => 'boolean'],
                'confidence' => ['type' => 'number'],
                'needs_approval' => ['type' => 'boolean'],
                'source_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'reason' => ['type' => 'string'],
            ],
            'required' => ['answer', 'grounded', 'confidence', 'needs_approval'],
        ]);
    }

    /**
     * Envelope comum da chamada (prompt travado, temperatura 0, JSON forcado).
     *
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $schema
     * @return array<string,mixed>
     */
    private function envelope(string $systemPrompt, array $payload, array $schema): array
    {
        return [
            'systemInstruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
            ]],
            'generationConfig' => [
                'temperature' => 0,
                'responseMimeType' => 'application/json',
                'responseSchema' => $schema,
            ],
        ];
    }

    /** Texto JSON dentro da resposta do Gemini, ou null se fora do formato. */
    private function innerJson(mixed $json): ?array
    {
        $text = data_get($json, 'candidates.0.content.parts.0.text');
        if (! is_string($text) || trim($text) === '') {
            return null;
        }

        $data = json_decode($text, true);

        return is_array($data) ? $data : null;
    }

    /** Parse DEFENSIVO da resposta do classify. Qualquer desvio -> unknown. */
    private function parse(mixed $json, string $model): AiClassification
    {
        $data = $this->innerJson($json);
        if ($data === null) {
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

    /** Parse DEFENSIVO da resposta do answer. Qualquer desvio -> unknown ("nao sei"). */
    private function parseAnswer(mixed $json, string $model): AiAnswer
    {
        $data = $this->innerJson($json);
        if ($data === null) {
            return AiAnswer::unknown('ia_resposta_invalida', $model);
        }

        $confidence = $data['confidence'] ?? null;
        if (! is_numeric($confidence) || ! is_string($data['answer'] ?? null)) {
            return AiAnswer::unknown('ia_resposta_invalida', $model);
        }

        // source_ids defensivo: so inteiros (quem chama ainda valida contra os candidatos).
        $sourceIds = [];
        foreach ((array) ($data['source_ids'] ?? []) as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $sourceIds[] = (int) $id;
            }
        }

        return new AiAnswer(
            answer: trim($data['answer']),
            grounded: (bool) ($data['grounded'] ?? false),
            confidence: max(0.0, min(1.0, (float) $confidence)),
            needsApproval: (bool) ($data['needs_approval'] ?? false),
            sourceIds: $sourceIds,
            reason: is_string($data['reason'] ?? null) ? $data['reason'] : '',
            model: $model,
            unknown: false,
        );
    }
}
