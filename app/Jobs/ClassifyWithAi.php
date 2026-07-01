<?php

namespace App\Jobs;

use App\Ai\AiClassification;
use App\Ai\AiClassificationRequest;
use App\Contracts\AiClassifier;
use App\Models\AiDecision;
use App\Models\AutoReplyRule;
use App\Models\Contact;
use App\Models\IncomingMessage;
use App\Whatsapp\AutoReply\AntiBanGuard;
use App\Whatsapp\AutoReply\RuleMatcher;
use App\Whatsapp\Secrets\SecretVault;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Camada 3 (IA) Fatia 1 — FALLBACK: roda so quando fluxo/regra NAO resolveram (o
 * pipeline so despacha este job nesse caso). Classifica a mensagem contra as regras
 * com "IA casa parecidas" ligada e:
 *   - responde com a RESPOSTA DA REGRA (via SendAutoReply -> Sender: todos os freios +
 *     R2 + idempotencia + {senha} resolvido local), OU
 *   - escala (silencia agora + loga; fila de aprovacao vem na Fatia 3), OU
 *   - silencia (nao classificou / erro / cota / modelo disse nao).
 *
 * Roda FORA do webhook (fila) porque a API tem latencia/429. Pre-checagens baratas
 * ANTES de gastar chamada. NUNCA envia valor de segredo pro modelo. Uma decisao por
 * mensagem (idempotente por incoming_message_id em ai_decisions).
 */
class ClassifyWithAi implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $incomingMessageId,
    ) {
    }

    public function handle(AiClassifier $ai, AntiBanGuard $guard, RuleMatcher $matcher, SecretVault $vault): void
    {
        $incoming = IncomingMessage::with('channel')->find($this->incomingMessageId);
        if (! $incoming || ! $incoming->channel) {
            return;
        }

        $accountId = (int) $incoming->account_id;
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

        // Candidatas: regras com "IA casa parecidas" ligada, elegiveis pro contato.
        $candidates = $matcher->aiCandidates($accountId, $incoming->channel_id, $jid);
        if ($candidates->isEmpty()) {
            // Sem candidatas -> nada a classificar (silencio estrutural, nao gasta API).
            return;
        }

        // MINIMIZACAO: so mensagem + gatilhos/exemplos. Nunca resposta/segredo.
        $payload = $candidates->map(fn (AutoReplyRule $r) => [
            'rule_id' => (int) $r->id,
            'triggers' => $r->triggerList()->pluck('value')->all(),
            'examples' => $r->aiExampleList(),
        ])->all();

        $topics = $guard->settingsFor($accountId)->aiApprovalTopics();

        $result = $ai->classify(new AiClassificationRequest($text, $payload, $topics));

        $this->decide($incoming, $contact, $aiMode, $result, $candidates, $guard, $vault);
    }

    private function decide(
        IncomingMessage $incoming,
        ?Contact $contact,
        string $aiMode,
        AiClassification $result,
        Collection $candidates,
        AntiBanGuard $guard,
        SecretVault $vault,
    ): void {
        $log = function (string $acao, ?string $motivo, ?AutoReplyRule $rule) use ($incoming, $contact, $result) {
            AiDecision::create([
                'account_id' => $incoming->account_id,
                'contact_id' => $contact?->id,
                'incoming_message_id' => $incoming->id,
                'matched_rule_id' => $rule?->id,
                'remote_jid' => $incoming->remote_jid,
                'intent' => $result->intent !== '' ? $result->intent : null,
                'confidence' => $result->confidence,
                'acao' => $acao,
                'motivo' => $motivo,
                'model' => $result->model,
            ]);
        };

        // Erro/cota/resposta invalida -> silencia.
        if ($result->unknown) {
            $log('silenciou', $result->reason ?: 'ia_indisponivel', null);

            return;
        }

        // A regra escolhida DEVE estar entre as candidatas (nunca confia em id de fora).
        $rule = $result->matchedRuleId !== null
            ? $candidates->firstWhere('id', $result->matchedRuleId)
            : null;

        if ($rule === null) {
            $log('silenciou', 'sem_regra', null);

            return;
        }

        // Guarda dura LOCAL: a IA NUNCA auto-envia uma resposta que contem segredo
        // ({senha:...}). Resolve o tema "dados bancarios/senhas" sem depender do modelo.
        $temSenha = $rule->responseList()->contains(fn ($r) => $vault->hasRef((string) $r));
        if ($temSenha) {
            $log('escalou', 'contem_senha', $rule);

            return;
        }

        // Modo do contato = aprovacao: nunca responde direto (so sugere -> Fatia 3).
        if ($aiMode === 'aprovacao') {
            $log('escalou', 'modo_aprovacao', $rule);

            return;
        }

        // Tema sensivel sinalizado pelo modelo (pagamento, compromissos, ...).
        if ($result->needsApproval) {
            $log('escalou', 'tema_aprovacao', $rule);

            return;
        }

        // Abaixo do limiar de confianca -> escala (humano decide).
        if ($result->confidence < $guard->aiConfidenceThreshold($incoming->account_id)) {
            $log('escalou', 'baixa_confianca', $rule);

            return;
        }

        // Modelo recomenda nao responder.
        if (! $result->shouldReply) {
            $log('silenciou', 'modelo_nao_responde', $rule);

            return;
        }

        // RESPONDE: despacha pela via NORMAL (delay humano). O Sender aplica todos os
        // freios + R2 + idempotencia + resolve {senha} local. A resposta e a DA REGRA
        // (RuleResponder), nunca texto inventado pela IA.
        $settings = $guard->settingsFor($incoming->account_id);
        $min = (int) $settings->delay_min_seconds;
        $max = (int) max($min, $settings->delay_max_seconds);

        SendAutoReply::dispatch($incoming->id, $rule->id)
            ->delay(now()->addSeconds(random_int($min, $max)));

        $log('respondeu', null, $rule);
    }
}
