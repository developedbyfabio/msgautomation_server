<?php

namespace App\Jobs;

use App\Ai\AiAnswerRequest;
use App\Ai\AiClassification;
use App\Ai\AiClassificationRequest;
use App\Contracts\AiClassifier;
use App\Models\AiDecision;
use App\Models\AutoReplyRule;
use App\Models\Contact;
use App\Models\IncomingMessage;
use App\Models\Knowledge;
use App\Models\PendingApproval;
use App\Tenancy\AccountContext;
use App\Whatsapp\AutoReply\AntiBanGuard;
use App\Whatsapp\AutoReply\RuleMatcher;
use App\Whatsapp\AutoReply\RuleResponder;
use App\Whatsapp\Secrets\SecretVault;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Camada 3 (IA) — FALLBACK: roda so quando fluxo/regra NAO resolveram (o pipeline so
 * despacha este job nesse caso). Dois degraus, na ordem:
 *
 *  1. (Fatia 1) casar REGRA por IA: classifica a mensagem contra as regras com "IA
 *     casa parecidas" ligada e responde com a RESPOSTA DA REGRA, escala ou silencia.
 *  2. (Fatia 2) so no modo `conhecimento` do contato, quando nenhuma regra casou:
 *     pede ao modelo resposta FUNDAMENTADA SO nas entradas candidatas da base
 *     (ativas, permitidas pro contato, APENAS low/medium — `high` NUNCA vai pro
 *     modelo e NUNCA e respondido direto). Sem grounding = "nao sei" = silencio.
 *
 * Toda resposta sai por SendAutoReply -> Sender (todos os freios + R2 + idempotencia
 * + {senha} resolvido local no POST). Roda FORA do webhook (fila) porque a API tem
 * latencia/429. Pre-checagens baratas ANTES de gastar chamada. NUNCA envia valor de
 * segredo pro modelo. Uma decisao por mensagem (idempotente por incoming_message_id
 * em ai_decisions).
 */
class ClassifyWithAi implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** $accountId (MT-0): contexto serializado; null (job antigo) -> resolve do incoming. */
    public function __construct(
        public readonly int $incomingMessageId,
        public readonly ?int $accountId = null,
    ) {
    }

    public function handle(
        AiClassifier $ai,
        AntiBanGuard $guard,
        RuleMatcher $matcher,
        SecretVault $vault,
        RuleResponder $responder,
    ): void {
        // MT-0: acha a mensagem SEM escopo (unico bypass do job) e restaura o
        // contexto ANTES de qualquer outra query (channel lazy ja escopado certo).
        $incoming = IncomingMessage::withoutAccountScope()->find($this->incomingMessageId);
        if (! $incoming) {
            return;
        }

        $accountId = (isset($this->accountId) ? $this->accountId : null) ?? (int) $incoming->account_id;
        app(AccountContext::class)->set($accountId);

        if (! $incoming->channel) {
            return;
        }
        $jid = (string) $incoming->remote_jid;
        $text = (string) $incoming->text;

        // Nada a classificar (midia sem legenda etc.).
        if (trim($text) === '') {
            return;
        }

        // Idempotencia: uma decisao por mensagem (protege re-entrega/retry do job).
        if (AiDecision::query()->where('incoming_message_id', $incoming->id)->exists()) {
            return;
        }

        // Pre-checagens (re-check no envio; estado pode ter mudado desde o enfileiramento).
        // Barato, ANTES de gastar chamada de API.
        if (! $guard->aiEligible($accountId, $jid)) {
            return;
        }

        $contact = Contact::query()->where('account_id', $accountId)->where('remote_jid', $jid)->first();
        $aiMode = (string) ($contact?->ai_mode ?: 'intencao');

        // rules_only: a IA nao age neste contato.
        if ($aiMode === 'rules_only') {
            return;
        }

        $topics = $guard->settingsFor($accountId)->aiApprovalTopics();

        // ---- Degrau 1 (Fatia 1): casar regra por IA -------------------------------
        // Candidatas: regras com "IA casa parecidas" ligada, elegiveis pro contato.
        $candidates = $matcher->aiCandidates($accountId, $incoming->channel_id, $jid);
        $sensitiveFlagged = false;

        if ($candidates->isNotEmpty()) {
            // MINIMIZACAO: so mensagem + gatilhos/exemplos. Nunca resposta/segredo.
            $payload = $candidates->map(fn (AutoReplyRule $r) => [
                'rule_id' => (int) $r->id,
                'triggers' => $r->triggerList()->pluck('value')->all(),
                'examples' => $r->aiExampleList(),
            ])->all();

            $result = $ai->classify(new AiClassificationRequest($text, $payload, $topics));

            if ($this->decideByRule($incoming, $contact, $aiMode, $result, $candidates, $guard, $vault)) {
                return;
            }

            // Nenhuma regra casou e o contato esta em modo `conhecimento` -> degrau 2.
            // Tema sensivel ja sinalizado aqui evita gastar a 2a chamada la embaixo.
            $sensitiveFlagged = $result->needsApproval;
        } elseif ($aiMode !== 'conhecimento') {
            // Sem candidatas e sem base -> nada a classificar (silencio estrutural).
            $this->registrarSemResposta($incoming); // MATCH-1
            return;
        }

        // ---- Degrau 2 (Fatia 2): base de conhecimento (so modo `conhecimento`) ----
        $this->answerFromKnowledge(
            $incoming,
            $contact,
            $ai,
            $guard,
            $vault,
            $responder,
            $topics,
            apiSpent: $candidates->isNotEmpty(),
            sensitiveFlagged: $sensitiveFlagged,
        );
    }

    /**
     * Degrau 1 — decide a partir da classificacao por REGRA (Fatia 1). Retorna true
     * quando a decisao foi tomada (logada); false SO quando nenhuma regra casou e o
     * contato esta em modo `conhecimento` (cai pro degrau 2, sem logar aqui — uma
     * decisao por mensagem).
     */
    private function decideByRule(
        IncomingMessage $incoming,
        ?Contact $contact,
        string $aiMode,
        AiClassification $result,
        Collection $candidates,
        AntiBanGuard $guard,
        SecretVault $vault,
    ): bool {
        $log = function (string $acao, ?string $motivo, ?AutoReplyRule $rule) use ($incoming, $contact, $result): AiDecision {
            $d = AiDecision::create([
                'account_id' => $incoming->account_id,
                'contact_id' => $contact?->id,
                'incoming_message_id' => $incoming->id,
                'matched_rule_id' => $rule?->id,
                'remote_jid' => $incoming->remote_jid,
                'intent' => $result->intent !== '' ? $result->intent : null,
                'confidence' => $result->confidence,
                'acao' => $acao,
                'origem' => 'regra',
                'motivo' => $motivo,
                'model' => $result->model,
            ]);
            // Kanban K-1 — evento de dominio (sem regra default; K-2/tags usam).
            event(new \App\Events\AiDecisionRecorded((int) $d->account_id, (int) $d->id, (string) $d->remote_jid, $acao, $d->intent));

            return $d;
        };

        // Fatia 3: toda escala vira pendencia revisavel no /revisao (nada e enviado
        // sem clique humano). Sugestao = a resposta DA REGRA candidata (template cru,
        // placeholders intactos — {senha:} nunca expandido aqui).
        $escala = function (string $motivo, AutoReplyRule $rule) use ($log, $incoming, $contact, $result): void {
            $decision = $log('escalou', $motivo, $rule);
            $this->abrirPendencia(
                $incoming,
                $contact,
                $decision,
                origin: 'regra',
                reason: $motivo,
                suggestion: (string) ($rule->responseList()->first() ?? '') !== '' ? (string) $rule->responseList()->first() : null,
                intent: $result->intent !== '' ? $result->intent : null,
                confidence: $result->confidence,
            );
        };

        // Erro/cota/resposta invalida -> silencia (a API esta com problema; nao gasta
        // a 2a chamada na base).
        if ($result->unknown) {
            $log('silenciou', $result->reason ?: 'ia_indisponivel', null);
            $this->registrarSemResposta($incoming); // MATCH-1

            return true;
        }

        // A regra escolhida DEVE estar entre as candidatas (nunca confia em id de fora).
        $rule = $result->matchedRuleId !== null
            ? $candidates->firstWhere('id', $result->matchedRuleId)
            : null;

        if ($rule === null) {
            if ($aiMode === 'conhecimento') {
                return false; // 2o fallback: base de conhecimento (quem loga e o degrau 2)
            }
            $log('silenciou', 'sem_regra', null);
            $this->registrarSemResposta($incoming); // MATCH-1

            return true;
        }

        // Guarda dura LOCAL: a IA NUNCA auto-envia uma resposta que contem segredo
        // ({senha:...}). Resolve o tema "dados bancarios/senhas" sem depender do modelo.
        $temSenha = $rule->responseList()->contains(fn ($r) => $vault->hasRef((string) $r));
        if ($temSenha) {
            $escala('contem_senha', $rule);

            return true;
        }

        // Modo do contato = aprovacao: nunca responde direto (so sugere na fila).
        if ($aiMode === 'aprovacao') {
            $escala('modo_aprovacao', $rule);

            return true;
        }

        // Tema sensivel sinalizado pelo modelo (pagamento, compromissos, ...).
        if ($result->needsApproval) {
            $escala('tema_aprovacao', $rule);

            return true;
        }

        // Abaixo do limiar de confianca -> escala (humano decide).
        if ($result->confidence < $guard->aiConfidenceThreshold($incoming->account_id)) {
            $escala('baixa_confianca', $rule);

            return true;
        }

        // Modelo recomenda nao responder.
        if (! $result->shouldReply) {
            $log('silenciou', 'modelo_nao_responde', $rule);
            $this->registrarSemResposta($incoming); // MATCH-1

            return true;
        }

        // RESPONDE: despacha pela via NORMAL (delay humano). O Sender aplica todos os
        // freios + R2 + idempotencia + resolve {senha} local. A resposta e a DA REGRA
        // (RuleResponder), nunca texto inventado pela IA.
        $settings = $guard->settingsFor($incoming->account_id);
        $min = (int) $settings->delay_min_seconds;
        $max = (int) max($min, $settings->delay_max_seconds);

        SendAutoReply::dispatch($incoming->id, $rule->id, accountId: (int) $incoming->account_id)
            ->delay(now()->addSeconds(random_int($min, $max)));

        $log('respondeu', null, $rule);

        return true;
    }

    /**
     * Degrau 2 (Fatia 2) — resposta fundamentada na base de conhecimento.
     *
     * Regras duras: `high` NUNCA vai pro modelo e NUNCA e respondido direto (escala);
     * resposta so sai se grounded nas entradas fornecidas (ids validados), acima do
     * limiar, sem tema de aprovacao e sem segredo ({senha:} nunca auto-enviado pela
     * IA). Qualquer outro caso silencia/escala e LOGA em ai_decisions (origem base).
     */
    private function answerFromKnowledge(
        IncomingMessage $incoming,
        ?Contact $contact,
        AiClassifier $ai,
        AntiBanGuard $guard,
        SecretVault $vault,
        RuleResponder $responder,
        array $topics,
        bool $apiSpent,
        bool $sensitiveFlagged,
    ): void {
        if ($contact === null) {
            return; // modo conhecimento exige contato (aiEligible ja garante; defensivo)
        }

        $accountId = (int) $incoming->account_id;

        $log = function (
            string $acao,
            ?string $motivo,
            array $knowledgeIds = [],
            ?string $resumo = null,
            ?float $confidence = null,
            ?string $model = null,
        ) use ($incoming, $contact, $vault): AiDecision {
            $d = AiDecision::create([
                'account_id' => $incoming->account_id,
                'contact_id' => $contact->id,
                'incoming_message_id' => $incoming->id,
                'remote_jid' => $incoming->remote_jid,
                'confidence' => $confidence,
                'acao' => $acao,
                'origem' => 'base',
                'knowledge_ids' => $knowledgeIds !== [] ? array_values($knowledgeIds) : null,
                // Resumo sempre REDIGIDO ({senha:nome} -> [senha: nome]); nunca o valor.
                'resposta_resumo' => $resumo !== null && $resumo !== ''
                    ? Str::limit($vault->redact($resumo), 180)
                    : null,
                'motivo' => $motivo,
                'model' => $model,
            ]);
            // Kanban K-1 — evento de dominio (sem regra default; K-2/tags usam).
            event(new \App\Events\AiDecisionRecorded((int) $d->account_id, (int) $d->id, (string) $d->remote_jid, $acao, $d->intent));

            return $d;
        };

        // Fatia 3: escala da base tambem vira pendencia (sugestao = resposta
        // fundamentada quando existe e e confiavel; template cru, sem expandir nada).
        $escala = function (AiDecision $decision, string $motivo, ?string $suggestion, ?float $confidence = null) use ($incoming, $contact): void {
            $this->abrirPendencia(
                $incoming,
                $contact,
                $decision,
                origin: 'base',
                reason: $motivo,
                suggestion: $suggestion !== null && trim($suggestion) !== '' ? $suggestion : null,
                intent: null,
                confidence: $confidence,
            );
        };

        // Candidatas: ativas + permitidas pro contato (pivo vazio = qualquer contato
        // com IA). Separa low/medium (vao ao modelo) de high (NUNCA vai).
        $todas = Knowledge::query()->candidatesFor($accountId, (int) $contact->id)->get();
        $entradas = $todas->filter(fn (Knowledge $k) => in_array($k->sensitivity, ['low', 'medium'], true))->values();
        $temHigh = $todas->contains(fn (Knowledge $k) => $k->sensitivity === 'high');

        if ($entradas->isEmpty()) {
            if ($temHigh) {
                // A resposta pode estar no conteudo high (que o modelo nao ve) ->
                // nunca responde direto; humano revisa no /revisao (sem sugestao).
                $escala($log('escalou', 'conteudo_high'), 'conteudo_high', null);

                return;
            }
            if ($apiSpent) {
                // Ja gastou a classificacao por regra (sem regra) e a base esta vazia.
                $log('silenciou', 'sem_conhecimento');
            $this->registrarSemResposta($incoming); // MATCH-1
            }

            // Base vazia sem chamada gasta: silencio estrutural, sem log (como Fatia 1).
            return;
        }

        // Tema sensivel ja sinalizado na classificacao por regra -> escala direto
        // (temas de aprovacao NUNCA sao respondidos; poupa a 2a chamada de API).
        if ($sensitiveFlagged) {
            $escala($log('escalou', 'tema_aprovacao'), 'tema_aprovacao', null);

            return;
        }

        // MINIMIZACAO: so id/titulo/conteudo das entradas low/medium permitidas.
        // Placeholders ({senha:nome}, {nome}, ...) vao INTACTOS — nunca expandidos.
        $payload = $entradas->map(fn (Knowledge $k) => [
            'id' => (int) $k->id,
            'title' => (string) $k->title,
            'content' => (string) $k->content,
        ])->all();

        $result = $ai->answer(new AiAnswerRequest((string) $incoming->text, $payload, $topics));

        // Erro/cota/JSON invalido -> "nao sei" -> silencia.
        if ($result->unknown) {
            $log('silenciou', $result->reason ?: 'ia_indisponivel', model: $result->model);
            $this->registrarSemResposta($incoming); // MATCH-1

            return;
        }

        // Entradas usadas DEVEM estar entre as candidatas (nunca confia em id de fora).
        $usadas = $entradas->whereIn('id', $result->sourceIds)->values();
        $usadasIds = $usadas->pluck('id')->map(fn ($id) => (int) $id)->all();

        // Sugestao confiavel pro humano = so resposta FUNDAMENTADA em entradas
        // validas (nunca sugere texto sem grounding — IA nao inventa nem na fila).
        $sugestao = ($result->grounded && $result->answer !== '' && $usadas->isNotEmpty())
            ? $result->answer
            : null;

        // Tema sensivel sinalizado pelo modelo -> nunca responde direto.
        if ($result->needsApproval) {
            $escala($log('escalou', 'tema_aprovacao', $usadasIds, $result->answer, $result->confidence, $result->model), 'tema_aprovacao', $sugestao, $result->confidence);

            return;
        }

        // IA nunca inventa: sem grounding (ou sem entradas validas) nao ha resposta.
        if (! $result->grounded || $result->answer === '' || $usadas->isEmpty()) {
            if ($temHigh) {
                $escala($log('escalou', 'conteudo_high', confidence: $result->confidence, model: $result->model), 'conteudo_high', null, $result->confidence);
            } else {
                $log('silenciou', 'sem_grounding', confidence: $result->confidence, model: $result->model);
            $this->registrarSemResposta($incoming); // MATCH-1
            }

            return;
        }

        // Guarda dura LOCAL (mesma da Fatia 1): a IA NUNCA auto-envia segredo — nem
        // com {senha:} na resposta, nem fundamentada em entrada que contem {senha:}.
        $temSenha = $vault->hasRef($result->answer)
            || $usadas->contains(fn (Knowledge $k) => $vault->hasRef((string) $k->content));
        if ($temSenha) {
            $escala($log('escalou', 'contem_senha', $usadasIds, $result->answer, $result->confidence, $result->model), 'contem_senha', $sugestao, $result->confidence);

            return;
        }

        // Abaixo do limiar de confianca -> escala (humano decide).
        if ($result->confidence < $guard->aiConfidenceThreshold($accountId)) {
            $escala($log('escalou', 'baixa_confianca', $usadasIds, $result->answer, $result->confidence, $result->model), 'baixa_confianca', $sugestao, $result->confidence);

            return;
        }

        // RESPONDE: placeholders comuns ({nome}/{saudacao}/{data}/{hora}) renderizados
        // LOCALMENTE agora; {senha:} nunca chega aqui (guarda acima). Envio pela via
        // NORMAL (delay humano) -> Sender (todos os freios + R2 + idempotencia).
        $textoFinal = $responder->render($result->answer, [
            'nome' => $incoming->push_name,
            'now' => now(),
        ]);

        $settings = $guard->settingsFor($accountId);
        $min = (int) $settings->delay_min_seconds;
        $max = (int) max($min, $settings->delay_max_seconds);

        SendAutoReply::dispatch($incoming->id, null, $textoFinal, accountId: (int) $incoming->account_id)
            ->delay(now()->addSeconds(random_int($min, $max)));

        $log('respondeu', null, $usadasIds, $result->answer, $result->confidence, $result->model);
    }

    /**
     * Fatia 3 — abre a pendencia de aprovacao humana pra uma decisao `escalou`.
     * NADA e enviado aqui: a pendencia so vira envio com clique no /revisao.
     * Idempotente por incoming_message_id (indice UNICO; corrida/re-entrega mantem
     * a primeira). `suggestion` guarda o TEMPLATE cru (placeholders intactos —
     * {senha:} nunca expandido; valor de segredo nunca persistido).
     */
    private function abrirPendencia(
        IncomingMessage $incoming,
        ?Contact $contact,
        AiDecision $decision,
        string $origin,
        ?string $reason,
        ?string $suggestion,
        ?string $intent = null,
        ?float $confidence = null,
    ): void {
        try {
            PendingApproval::create([
                'account_id' => $incoming->account_id,
                'contact_id' => $contact?->id,
                'incoming_message_id' => $incoming->id,
                'ai_decision_id' => $decision->id,
                'remote_jid' => $incoming->remote_jid,
                'suggested_response' => $suggestion,
                'origin' => $origin,
                'reason' => $reason,
                'intent' => $intent,
                'confidence' => $confidence,
                'status' => 'pending',
            ]);
        } catch (UniqueConstraintViolationException) {
            // Ja existe pendencia pra esta mensagem (corrida) -> mantem a primeira.
        }
    }

    /** MATCH-1 — IA terminou em silencio: o sem-match e registrado (oportunidade). */
    private function registrarSemResposta(\App\Models\IncomingMessage $incoming): void
    {
        \App\Models\UnmatchedMessage::record(
            (int) $incoming->account_id,
            (string) $incoming->remote_jid,
            $incoming->text,
        );
    }
}
