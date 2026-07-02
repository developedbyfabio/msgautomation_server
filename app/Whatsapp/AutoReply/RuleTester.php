<?php

namespace App\Whatsapp\AutoReply;

use App\Models\Contact;
use App\Whatsapp\Secrets\SecretMissingException;
use App\Whatsapp\Secrets\SecretVault;

/**
 * S4 — testador (dry-run). Dado um texto de exemplo (+ contato opcional), diz QUAL
 * regra casaria, QUAL gatilho, a RESPOSTA resolvida (placeholders aplicados) e se
 * algum freio BLOQUEARIA — tudo SEM enviar e SEM mexer em freios/contadores.
 *
 * Nao chama o Sender (que cria log/claim). O guard->check e somente-leitura (le
 * cache/DB, nao grava), entao da pra reusar pra reportar o "bloquearia".
 */
class RuleTester
{
    public function __construct(
        private RuleMatcher $matcher,
        private RuleResponder $responder,
        private AntiBanGuard $guard,
        private SecretVault $vault,
    ) {
    }

    /** Rotulos amigaveis dos motivos de bloqueio (freios). */
    private const MOTIVOS = [
        'kill_switch' => 'Robo desligado (kill switch OFF)',
        'fora_da_janela' => 'Fora da janela de horario',
        'nao_aprovado' => 'Contato nao aprovado (politica allowlist)',
        'opt_out' => 'Contato silenciado (off)',
        'grupo' => 'E um grupo (pulado)',
        'from_me' => 'Mensagem do proprio numero (ignorada)',
        'rate_contato' => 'Intervalo por contato',
        'cooldown_dia' => 'Cooldown da regra: ja respondeu hoje',
        'cooldown' => 'Cooldown da regra: aguardando o intervalo',
        'intervalo_minimo' => 'Teto de volume: intervalo minimo entre envios',
        'teto_minuto' => 'Teto de volume: limite por minuto',
        'teto_dia' => 'Teto de volume: limite por dia',
    ];

    public function test(int $accountId, ?int $channelId, string $sample, ?int $contactId = null, bool $revealSecrets = false): array
    {
        $sample = trim($sample);
        if ($sample === '') {
            return ['ok' => false, 'erro' => 'Digite uma mensagem de exemplo.'];
        }

        $contact = $contactId
            ? Contact::query()->where('account_id', $accountId)->find($contactId)
            : null;
        $jid = $contact?->remote_jid;

        // Fatia 0: todas as que casam, vencedora primeiro (auto-resolucao por especificidade).
        $matching = $this->matcher->allMatching($accountId, $channelId, $sample, $jid);
        $rule = $matching[0] ?? null;

        // Camada 3 — info da IA (dry-run, SEM chamar a API). So com contato escolhido.
        $ai = $this->aiInfo($accountId, $channelId, $contact, $jid);

        // T-1 — transparencia do escopo por TAG: regras cujo gatilho casaria mas que
        // ficaram fora porque o contato nao tem a(s) tag(s).
        $foraPorTag = $this->foraPorTag($accountId, $channelId, $sample, $contact, $matching);

        if ($rule === null) {
            return [
                'ok' => true,
                'matched' => false,
                'contato' => $contact?->push_name ?: ($jid ? \Illuminate\Support\Str::before($jid, '@') : null),
                'ai' => $ai,
                'fora_por_tag' => $foraPorTag,
            ];
        }

        // "Tambem casariam" (perdedoras do conflito) — transparencia.
        $tambem = [];
        foreach (array_slice($matching, 1) as $outra) {
            $g = $this->matcher->firstMatchingTrigger($outra, $sample);
            $tambem[] = '#' . $outra->id . ($g ? ' (' . $g['value'] . ')' : '');
        }

        $trigger = $this->matcher->firstMatchingTrigger($rule, $sample);
        $respostas = $rule->responseList();

        // Resolve placeholders comuns ({nome}/{saudacao}/...). {senha:...} fica intacto aqui.
        $rendered = $respostas->isNotEmpty()
            ? $this->responder->render((string) $respostas->first(), [
                'nome' => $contact?->push_name,
                'now' => now(),
            ])
            : null;

        // S6: senha MASCARADA por padrao. Revelar e deliberado e transiente (nao persiste).
        $temSenha = $rendered !== null && $this->vault->hasRef($rendered);
        if ($rendered === null) {
            $resposta = null;
        } elseif ($temSenha && $revealSecrets) {
            try {
                $resposta = $this->vault->resolve($accountId, $rendered);
            } catch (SecretMissingException) {
                $resposta = $this->vault->mask($rendered);
            }
        } else {
            $resposta = $this->vault->mask($rendered); // sem senha, mask e no-op
        }

        // Freio que bloquearia (somente-leitura). So da pra avaliar os freios de contato
        // se houver contato escolhido; senao reportamos so o que independe de contato.
        [$bloqueio, $bloqueioLabel] = $this->avaliarFreio($accountId, $jid, $rule->id, $contact !== null);

        // S3: quadro COMPLETO dos freios (transparencia) — so com contato escolhido.
        $freios = ($contact !== null && $jid !== null)
            ? $this->guard->breakdown($accountId, $jid, $rule->id)
            : [];

        return [
            'ok' => true,
            'matched' => true,
            'rule_id' => $rule->id,
            'trigger' => $trigger ? (RuleMatcher::typeLabel($trigger['type']) . ': ' . $trigger['value']) : null,
            'trigger_precision' => $trigger['precision'] ?? 'exato',
            'resposta' => $resposta,
            'tem_senha' => $temSenha,
            'respostas_total' => $respostas->count(),
            'contato' => $contact?->push_name ?: ($jid ? \Illuminate\Support\Str::before($jid, '@') : null),
            'bloqueio' => $bloqueio,
            'bloqueio_label' => $bloqueioLabel,
            'freios' => $freios,
            'tambem' => $tambem, // outras regras que tambem casariam (perderam por especificidade)
            'ai' => $ai,
            // T-1: por que casou/nao casou por tag (transparencia do escopo).
            'casou_por_tag' => ($rule->scope ?: 'global') === 'tags'
                ? $rule->tags()->pluck('name')->implode(', ')
                : null,
            'fora_por_tag' => $foraPorTag,
        ];
    }

    /**
     * T-1 — regras com escopo por TAG cujo gatilho casaria a mensagem mas que ficaram
     * FORA do match (o contato nao tem nenhuma das tags). So com contato escolhido.
     *
     * @param  array<int,\App\Models\AutoReplyRule>  $matching
     * @return array<int,string>
     */
    private function foraPorTag(int $accountId, ?int $channelId, string $sample, ?Contact $contact, array $matching): array
    {
        if ($contact === null) {
            return [];
        }

        $matchingIds = array_map(fn ($r) => (int) $r->id, $matching);
        $out = [];

        $regrasTag = \App\Models\AutoReplyRule::query()
            ->with(['triggers', 'tags'])
            ->where('account_id', $accountId)
            ->where('enabled', true)
            ->where('scope', 'tags')
            ->where(function ($q) use ($channelId) {
                $q->whereNull('channel_id');
                if ($channelId !== null) {
                    $q->orWhere('channel_id', $channelId);
                }
            })
            ->get();

        foreach ($regrasTag as $r) {
            if (in_array((int) $r->id, $matchingIds, true)) {
                continue; // elegivel — casou normalmente
            }
            if ($this->matcher->listMatches($r->triggerList(), $sample)) {
                $out[] = '#' . $r->id . ' (exige tag: ' . $r->tags->pluck('name')->implode(', ') . ')';
            }
        }

        return $out;
    }

    /**
     * Camada 3 — quadro da IA no dry-run (NAO chama a API). Diz se a IA esta ligada
     * (global + contato) e quantas regras candidatas existem pro contato. No modo
     * `conhecimento` (Fatia 2), informa tambem quantas entradas da base sao
     * candidatas (ativas, permitidas, so low/medium — as que iriam ao modelo).
     *
     * @return array{global_ligada:bool,contato_ligada:bool,modo:string,candidatas:int,base_candidatas:int}|null
     */
    private function aiInfo(int $accountId, ?int $channelId, ?\App\Models\Contact $contact, ?string $jid): ?array
    {
        if ($contact === null || $jid === null) {
            return null;
        }

        $modo = (string) ($contact->ai_mode ?: 'intencao');
        $settings = $this->guard->settingsFor($accountId);

        return [
            'global_ligada' => (bool) $settings->ai_enabled,
            'contato_ligada' => (bool) $contact->ai_enabled && $modo !== 'rules_only',
            'modo' => $modo,
            'candidatas' => $this->matcher->aiCandidates($accountId, $channelId, $jid)->count(),
            'base_candidatas' => $modo === 'conhecimento'
                ? \App\Models\Knowledge::query()->candidatesFor($accountId, (int) $contact->id)
                    ->whereIn('sensitivity', ['low', 'medium'])->count()
                : 0,
            // Fatia 4: configuracao vigente, pro dry-run mostrar com o que a IA decide.
            'limiar' => (float) $settings->ai_confidence_threshold,
            'temas' => $settings->aiApprovalTopics(),
        ];
    }

    /** @return array{0:?string,1:?string} [motivo, label] ou [null,null] se passaria. */
    private function avaliarFreio(int $accountId, ?string $jid, int $ruleId, bool $temContato): array
    {
        if (! $temContato || $jid === null) {
            // Sem contato: avalia so o que independe de contato (kill switch / janela).
            $settings = $this->guard->settingsFor($accountId);
            if (! $settings->enabled) {
                return ['kill_switch', self::MOTIVOS['kill_switch']];
            }

            return [null, null];
        }

        $decision = $this->guard->check('auto', $accountId, $jid, false, $ruleId);
        if ($decision->allowed) {
            return [null, null];
        }

        return [$decision->reason, self::MOTIVOS[$decision->reason] ?? $decision->reason];
    }
}
