<?php

namespace App\Whatsapp\Flows;

use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\FlowSession;
use App\Whatsapp\AutoReply\RuleMatcher;

/**
 * Fatia A — motor de fluxos (menus condicionais, DETERMINISTICO, sem IA, sem enviar).
 * Gerencia o estado de conversa (flow_sessions) e a navegacao. NAO envia nada: devolve
 * "diretivas" (texto a enviar + status) que o pipeline resolve/envia depois.
 *
 * Regras-chave (desenho aprovado):
 *  - sessao ATIVA tem prioridade: a mensagem e navegacao, nunca cai nas regras;
 *  - timeout de inatividade (por fluxo) expira a sessao preguicosamente;
 *  - opcao invalida -> re-pergunta (nao avanca); nó final -> encerra;
 *  - reentrada: gatilho de entrada durante a sessao reinicia na raiz;
 *  - sair/cancelar encerra.
 */
class FlowEngine
{
    /** Palavras que encerram a sessao a qualquer momento. */
    private const CANCEL = ['sair', 'cancelar'];

    public function __construct(private RuleMatcher $matcher)
    {
    }

    /** Sessao ATIVA e nao expirada do contato (expira preguicosamente). Ou null. */
    public function activeSession(int $accountId, string $jid): ?FlowSession
    {
        $session = FlowSession::query()
            ->where('account_id', $accountId)->where('remote_jid', $jid)->where('status', 'active')
            ->latest('id')->first();

        if ($session === null) {
            return null;
        }
        if ($session->isExpired()) {
            $session->update(['status' => 'expired']);

            return null;
        }

        return $session;
    }

    /** Fluxo elegivel cujo gatilho de entrada casa (primeiro por id; escopo respeitado). */
    public function entryFlow(int $accountId, string $text, string $jid): ?Flow
    {
        $flows = Flow::query()->with(['triggers', 'contacts', 'tags'])
            ->where('account_id', $accountId)->where('enabled', true)->orderBy('id')->get();

        foreach ($flows as $flow) {
            if (! $this->scopeEligible($flow, $jid)) {
                continue;
            }
            if ($this->matcher->listMatches($flow->triggerList(), $text)) {
                return $flow;
            }
        }

        return null;
    }

    /** Inicia um fluxo: cria sessao ativa na raiz e devolve a diretiva do nó raiz. */
    public function start(int $accountId, Flow $flow, string $jid): array
    {
        // Encerra qualquer sessao ativa anterior do contato (reinicio limpo).
        FlowSession::query()->where('account_id', $accountId)->where('remote_jid', $jid)
            ->where('status', 'active')->update(['status' => 'cancelled']);

        $root = $flow->rootNode();
        if ($root === null) {
            return ['text' => null, 'status' => 'none', 'session' => null];
        }

        $session = FlowSession::create([
            'account_id' => $accountId, 'flow_id' => $flow->id, 'remote_jid' => $jid,
            'current_node_id' => $root->id, 'status' => 'active',
            'started_at' => now(), 'last_activity_at' => now(),
            'expires_at' => now()->addSeconds(max(60, (int) $flow->timeout_seconds)),
        ]);

        return $this->emit($session, $flow, $root);
    }

    /** Avanca a sessao com a resposta do contato. Devolve a diretiva (texto + status). */
    public function advance(FlowSession $session, string $text): array
    {
        $flow = $session->flow;
        $node = $session->currentNode();
        if ($flow === null || $node === null) {
            $session->update(['status' => 'completed']);

            return ['text' => null, 'status' => 'completed', 'session' => $session];
        }

        $in = $this->norm($text);

        // Sair/cancelar.
        if (in_array($in, self::CANCEL, true)) {
            $session->update(['status' => 'cancelled', 'last_activity_at' => now()]);

            return ['text' => 'Ok, encerrei o atendimento. E so chamar de novo quando quiser.', 'status' => 'cancelled', 'session' => $session];
        }

        // Reentrada: gatilho de entrada do fluxo reinicia na raiz.
        if ($this->matcher->listMatches($flow->triggerList(), $text)) {
            return $this->start((int) $session->account_id, $flow, (string) $session->remote_jid);
        }

        // Casa uma opcao do nó atual?
        foreach ($node->options as $opt) {
            if ($this->matchesOption($in, (string) $opt->input)) {
                $next = $opt->next_node_id ? FlowNode::find($opt->next_node_id) : null;
                if ($next === null) {
                    $session->update(['status' => 'completed', 'last_activity_at' => now()]);

                    return ['text' => null, 'status' => 'completed', 'session' => $session];
                }

                return $this->emit($session, $flow, $next);
            }
        }

        // Opcao invalida -> re-pergunta o nó atual (nao avanca). Renova a atividade.
        $session->update(['last_activity_at' => now(), 'expires_at' => now()->addSeconds(max(60, (int) $flow->timeout_seconds))]);

        return ['text' => $this->invalidPrefix($flow, $node) . $node->message, 'status' => 'active', 'session' => $session];
    }

    // ---- Simulacao (testador, SEM persistir sessao nem enviar) --------------

    /** Diretiva do nó raiz pra simulacao. Nao cria sessao. */
    public function simStart(Flow $flow): array
    {
        $root = $flow->rootNode();

        return $root ? $this->simEmit($root) : ['node_id' => null, 'text' => null, 'status' => 'none'];
    }

    /** Avanca a simulacao a partir do nó atual, sem tocar no banco de sessoes. */
    public function simAdvance(Flow $flow, ?int $currentNodeId, string $text): array
    {
        $node = $currentNodeId ? FlowNode::find($currentNodeId) : null;
        if ($node === null || (int) $node->flow_id !== (int) $flow->id) {
            return ['node_id' => null, 'text' => null, 'status' => 'completed'];
        }

        $in = $this->norm($text);
        if (in_array($in, self::CANCEL, true)) {
            return ['node_id' => null, 'text' => 'Ok, encerrei o atendimento.', 'status' => 'cancelled'];
        }
        if ($this->matcher->listMatches($flow->triggerList(), $text)) {
            return $this->simStart($flow); // reentrada -> raiz
        }
        foreach ($node->options as $opt) {
            if ($this->matchesOption($in, (string) $opt->input)) {
                $next = $opt->next_node_id ? FlowNode::find($opt->next_node_id) : null;

                return $next ? $this->simEmit($next) : ['node_id' => null, 'text' => null, 'status' => 'completed'];
            }
        }

        return ['node_id' => $node->id, 'text' => $this->invalidPrefix($flow, $node) . $node->message, 'status' => 'active'];
    }

    private function simEmit(FlowNode $node): array
    {
        // Fatia 5: no simulador o handoff so mostra a mensagem/terminal — SEM efeitos
        // colaterais (mute/kanban), como toda simulacao (dry-run).
        if ($node->isHandoff()) {
            return ['node_id' => $node->id, 'text' => (string) $node->message, 'status' => 'handed_off'];
        }

        $encerra = $node->isFinal() || ! $node->options()->exists();

        return ['node_id' => $node->id, 'text' => (string) $node->message, 'status' => $encerra ? 'completed' : 'active'];
    }

    /**
     * Fatia 5 — no de HANDOFF: envia a mensagem do no, PAUSA o robo pro contato
     * (MESMO mecanismo do mute da UI: Contact.auto_reply_mode='off'), move o card
     * pro em_atendimento (BoardEngine) e encerra a sessao (terminal 'handed_off';
     * string(16) comporta, aditivo). A despedida vai pelo MESMO caminho de dispatch
     * (o status 'handed_off' na diretiva sinaliza a isencao do gate de contato no
     * envio — o 'off' e do proprio handoff, nao pode bloquear a propria despedida).
     */
    private function emitHandoff(FlowSession $session, FlowNode $node): array
    {
        // 4) sessao terminal (activeSession so considera 'active').
        $session->update([
            'current_node_id' => $node->id,
            'status' => 'handed_off',
            'last_activity_at' => now(),
        ]);

        // 2) pausa o robo pro contato — mecanismo REUSADO do mute da UI
        //    (Conversas::muteConfirmed usa exatamente este updateOrCreate).
        \App\Models\Contact::query()->updateOrCreate(
            ['account_id' => (int) $session->account_id, 'remote_jid' => (string) $session->remote_jid],
            ['auto_reply_mode' => 'off'],
        );

        // 3) card -> em_atendimento (BoardEngine: mesmo motor de cards/transicoes,
        //    idempotente por evento). Kanban e observador: erro dele NUNCA derruba o fluxo.
        try {
            app(\App\Kanban\BoardEngine::class)->moveToColumnSlug(
                'em_atendimento', (int) $session->account_id, (string) $session->remote_jid,
                'handoff', (int) $session->id,
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Handoff: falha isolada ao mover card (fluxo segue).', ['erro' => $e->getMessage()]);
        }

        // K-1: evento de dominio, como os demais nos.
        event(new \App\Events\FlowNodeReached(
            (int) $session->account_id, (int) $session->id, (string) $session->remote_jid,
            (int) $node->id, 'handed_off',
        ));

        // 1) a mensagem do no segue pelo caminho normal (dispatchFlowReply -> Sender).
        return ['text' => $node->message, 'status' => 'handed_off', 'session' => $session];
    }

    /** Emite a diretiva pra um nó: menu -> espera; final (ou menu sem opcoes) -> encerra. */
    private function emit(FlowSession $session, Flow $flow, FlowNode $node): array
    {
        // Fatia 5 — handoff tem execucao propria (mensagem + mute + kanban + terminal).
        if ($node->isHandoff()) {
            return $this->emitHandoff($session, $node);
        }

        $temOpcoes = $node->options()->exists();
        $encerra = $node->isFinal() || ! $temOpcoes;

        $session->update([
            'current_node_id' => $node->id,
            'status' => $encerra ? 'completed' : 'active',
            'last_activity_at' => now(),
            'expires_at' => now()->addSeconds(max(60, (int) $flow->timeout_seconds)),
        ]);

        // Kanban K-1 — evento de dominio (sem regra default; disponivel pra K-2/tags).
        event(new \App\Events\FlowNodeReached(
            (int) $session->account_id, (int) $session->id, (string) $session->remote_jid,
            (int) $node->id, $encerra ? 'completed' : 'active',
        ));

        return ['text' => $node->message, 'status' => $encerra ? 'completed' : 'active', 'session' => $session];
    }

    private function matchesOption(string $normInput, string $optInput): bool
    {
        return $normInput === $this->norm($optInput);
    }

    /**
     * MATCH-1: normalizador UNICO (caixa, acento, pontuacao, emoji, espacos) —
     * "1", " 1 ", "1.", "1)" e "1️⃣" viram "1"; "sair"/"SAIR!"/"saír" idem.
     */
    private function norm(string $value): string
    {
        return \App\Whatsapp\TextNormalizer::normalize($value);
    }

    private function invalidPrefix(Flow $flow, FlowNode $node): string
    {
        $base = $flow->invalid_message ?: 'Opcao invalida. Escolha uma das opcoes abaixo.';

        return $base . "\n\n";
    }

    /**
     * Escopo: 'contatos' so se o remetente esta na lista; 'tags' (T-1) se o
     * remetente tem QUALQUER tag do fluxo (avaliado na hora — tag entra/sai,
     * o alcance muda na proxima mensagem); 'global' sempre.
     */
    private function scopeEligible(Flow $flow, string $jid): bool
    {
        $scope = $flow->scope ?: 'global';
        if ($scope === 'global') {
            return true;
        }

        if ($scope === 'tags') {
            $contact = \App\Models\Contact::query()->where('remote_jid', $jid)->first();
            if ($contact === null) {
                return false;
            }
            $flowTags = $flow->relationLoaded('tags') ? $flow->tags : $flow->tags()->get();

            return $flowTags->pluck('id')->intersect($contact->tags()->pluck('tags.id'))->isNotEmpty();
        }

        $contatos = $flow->relationLoaded('contacts') ? $flow->contacts : $flow->contacts()->get();

        return $contatos->contains(fn ($c) => $c->remote_jid === $jid);
    }
}
