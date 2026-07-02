<?php

namespace App\Livewire;

use App\Ai\KnowledgeWriter;
use App\Models\Account;
use App\Models\AiDecision;
use App\Models\AutoReplyLog;
use App\Models\PendingApproval;
use App\Whatsapp\AutoReply\RuleConflictDetector;
use App\Whatsapp\AutoReply\RuleResponder;
use App\Whatsapp\AutoReply\RuleWriter;
use App\Whatsapp\AutoReply\Sender;
use App\Whatsapp\Secrets\SecretVault;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Camada 3 Fatia 3 — painel de revisao humana (/revisao). O que a IA ESCALOU vira
 * pendencia aqui; o Fabio decide: Enviar (a sugestao), Editar (ajusta e envia) ou
 * Ignorar. NADA e enviado sem clique. Este padrao de gate humano (revisar ->
 * aprovar -> enviar) sera reusado pelas campanhas proativas.
 *
 * SEGURANCA:
 *  - Sugestao aparece MASCARADA na lista ({senha:x} -> [senha: x ....]); o valor
 *    NUNCA e exibido/logado; resolucao so no envio (Sender).
 *  - Editar NAO pode inserir referencia {senha:} nova (so manter as que ja vieram
 *    da sugestao — aquelas nasceram de regra/entrada que a guarda de escopo ja
 *    validou pro contato). Placeholders resolvidos SO no envio.
 *  - Envio sai pelo Sender em modo 'aprovacao': claim de idempotencia por mensagem,
 *    tetos protetivos + opt-out + R2 de opt-out. Kill switch do robo NAO bloqueia
 *    (decisao humana, politica R1 do envio manual).
 *  - Pendencia decidida TRAVA (botoes somem; acoes viram no-op).
 */
#[Layout('components.layouts.app')]
class Revisao extends Component
{
    public string $filter = 'pendentes'; // pendentes | decididas | expiradas | decisoes

    public ?int $confirmingSendId = null;
    public ?int $editingId = null;
    public string $editText = '';

    // Fatia 4 — promocao ("virar regra" / "virar entrada da base").
    public string $promoteType = '';     // pendencia | decisao
    public ?int $promoteId = null;
    public string $promoteKind = '';     // '' fechado | regra | base
    public string $pTrigger = '';
    public string $pTriggerType = 'contains'; // contains | exact | starts_with
    public string $pResponse = '';
    public string $pScope = 'contatos';  // contatos (default: o contato da pendencia) | global
    public bool $pAiMatch = true;
    public string $pTitle = '';
    public string $pContent = '';
    public string $pSensitivity = 'medium';
    public bool $pRestrict = true;       // restringir a entrada ao contato da pendencia

    /** Rotulos pt-BR dos motivos de escala. */
    public const MOTIVOS = [
        'baixa_confianca' => 'Confianca abaixo do limiar',
        'tema_aprovacao' => 'Tema sensivel (sempre exige aprovacao)',
        'contem_senha' => 'Resposta contem senha do cofre',
        'modo_aprovacao' => 'Contato em modo aprovacao',
        'conteudo_high' => 'Possivel resposta em conteudo sensivel (high)',
    ];

    public function mount(): void
    {
        // Expiracao leve (lazy): pendencias velhas viram 'expired' ao abrir a tela.
        PendingApproval::expireStale($this->accountId());
    }

    public function setFilter(string $filter): void
    {
        if (in_array($filter, ['pendentes', 'decididas', 'expiradas', 'decisoes'], true)) {
            $this->filter = $filter;
        }
    }

    // ---- Enviar (com confirmacao) -------------------------------------------

    public function askSend(int $id): void
    {
        $p = $this->find($id);
        if ($p && $p->isActionable() && trim((string) $p->suggested_response) !== '') {
            $this->confirmingSendId = $id;
        }
    }

    public function cancelSend(): void
    {
        $this->confirmingSendId = null;
    }

    public function confirmSend(Sender $sender, RuleResponder $responder): void
    {
        $p = $this->find($this->confirmingSendId);
        $this->confirmingSendId = null;

        if (! $p || ! $p->isActionable()) {
            $this->dispatch('toast', message: 'Pendencia ja decidida ou expirada.', type: 'error');

            return;
        }

        $template = trim((string) $p->suggested_response);
        if ($template === '') {
            $this->dispatch('toast', message: 'Sem sugestao pra enviar — use Editar.', type: 'error');

            return;
        }

        $this->despachar($p, $template, 'approved', $sender, $responder);
    }

    // ---- Editar (modal) -> envia ---------------------------------------------

    public function startEdit(int $id): void
    {
        $p = $this->find($id);
        if (! $p || ! $p->isActionable()) {
            return;
        }
        $this->editingId = $p->id;
        // Template CRU pra edicao: placeholder {senha:x} visivel (referencia, nunca
        // o valor) — mesmo padrao do editor de regras.
        $this->editText = (string) $p->suggested_response;
        $this->resetValidation();
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->editText = '';
        $this->resetValidation();
    }

    public function confirmEdit(Sender $sender, RuleResponder $responder, SecretVault $vault): void
    {
        $p = $this->find($this->editingId);
        if (! $p || ! $p->isActionable()) {
            $this->cancelEdit();
            $this->dispatch('toast', message: 'Pendencia ja decidida ou expirada.', type: 'error');

            return;
        }

        $texto = trim($this->editText);
        if ($texto === '') {
            $this->addError('editText', 'Digite a resposta.');

            return;
        }
        if (mb_strlen($texto) > 4000) {
            $this->addError('editText', 'Resposta longa demais (max 4000).');

            return;
        }

        // Guarda de segredo (mesma politica das regras): a edicao so pode conter
        // {senha:...} que JA estava na sugestao original — aquela referencia nasceu
        // de regra/entrada validada pro contato pela guarda de escopo. Inserir
        // referencia NOVA aqui daria senha a quem a guarda nunca aprovou -> bloqueia.
        $permitidas = $vault->refsIn((string) $p->suggested_response);
        $novas = array_diff($vault->refsIn($texto), $permitidas);
        if ($novas !== []) {
            $this->addError('editText', 'Nao e permitido inserir {senha:...} novo na edicao. Senha so sai por regra/entrada com escopo validado pro contato (crie/ajuste em Regras ou Conhecimento).');

            return;
        }

        // V-1 — AVISO (nao bloqueio): referencia desconhecida sai crua.
        $desconhecidas = \App\Models\Variable::unknownRefs($this->accountId(), $texto);
        if ($desconhecidas !== []) {
            $this->dispatch('toast', message: 'Aviso: referencia(s) desconhecida(s): {' . implode('}, {', $desconhecidas) . '} — enviado assim mesmo, cru.', type: 'error');
        }

        $this->cancelEdit();
        $this->despachar($p, $texto, 'edited', $sender, $responder);
    }

    // ---- Ignorar ---------------------------------------------------------------

    public function ignore(int $id): void
    {
        $p = $this->find($id);
        if (! $p || ! $p->isActionable()) {
            return;
        }

        $p->update(['status' => 'rejected', 'decided_at' => now()]);
        $this->dispatch('toast', message: 'Pendencia ignorada. Nada foi enviado.');
    }

    // ---- envio (comum a Enviar/Editar) ----------------------------------------

    /**
     * Envia pelo Sender em modo 'aprovacao' (inline, como o envio manual — quem
     * clicou esta aqui). Placeholders comuns ({nome}/{saudacao}/...) resolvidos SO
     * agora; {senha:} SO no POST (Sender). So marca decidida se o envio SAIU;
     * bloqueio (opt-out/teto) mantem a pendencia e avisa o motivo.
     */
    private function despachar(PendingApproval $p, string $template, string $statusFinal, Sender $sender, RuleResponder $responder): void
    {
        $incoming = $p->incomingMessage()->with('channel')->first();
        if (! $incoming || ! $incoming->channel) {
            $this->dispatch('toast', message: 'Mensagem original indisponivel — nao da pra enviar.', type: 'error');

            return;
        }

        $texto = $responder->render($template, [
            'nome' => $incoming->push_name,
            'now' => now(),
        ]);

        $log = $sender->send(
            mode: 'aprovacao',
            channel: $incoming->channel,
            jid: $p->remote_jid,
            text: $texto,
            incomingMessageId: $incoming->id,
        );

        if ($log->status !== 'sent') {
            $motivo = [
                'opt_out' => 'contato silenciado (off)',
                'intervalo_minimo' => 'teto de volume (intervalo minimo)',
                'teto_minuto' => 'teto de volume (por minuto)',
                'teto_dia' => 'teto de volume (por dia)',
                'senha_ausente' => 'senha nao encontrada no cofre',
                'erro_envio' => 'falha no envio',
            ][$log->motivo] ?? ($log->motivo ?: 'bloqueado');
            $this->dispatch('toast', message: 'Nao enviado: ' . $motivo . '. A pendencia continua na fila.', type: 'error');

            return;
        }

        $p->update([
            'status' => $statusFinal,
            'decided_at' => now(),
            'sent_auto_reply_log_id' => $log->id,
        ]);

        $this->dispatch('toast', message: $statusFinal === 'edited' ? 'Resposta editada enviada.' : 'Resposta enviada.');
    }

    // ---- Fatia 4: promocao ("virar regra" / "virar entrada da base") ------------

    /**
     * Abre o modal PRE-PREENCHIDO. Fonte: pendencia (qualquer status) ou decisao da
     * IA em que ela respondeu sozinha (aba de auditoria). Promocao e UNICA por item
     * (promovida trava). A IA nunca grava nada sozinha — tudo aqui e clique humano.
     */
    public function startPromote(string $kind, string $type, int $id): void
    {
        if (! in_array($kind, ['regra', 'base'], true)) {
            return;
        }

        $fonte = $this->promoteSource($type, $id);
        if ($fonte === null || $fonte->isPromoted()) {
            return;
        }

        $mensagem = (string) ($fonte->incomingMessage?->text ?? '');
        $resposta = $this->promoteResponseFor($fonte);

        $this->promoteType = $type;
        $this->promoteId = $id;
        $this->promoteKind = $kind;
        $this->resetValidation();

        if ($kind === 'regra') {
            $this->pTrigger = $mensagem;
            $this->pTriggerType = 'contains';
            $this->pResponse = $resposta;
            // Default CONSERVADOR: escopo "Contatos Especificos" com o contato da
            // pendencia. Trocar pra global so sem {senha:} (guarda dura no salvar).
            $this->pScope = 'contatos';
            $this->pAiMatch = true;
        } else {
            $this->pTitle = Str::limit(trim((string) ($fonte->intent ?: $mensagem)), 100, '');
            $this->pContent = $resposta;
            $this->pSensitivity = 'medium';
            $this->pRestrict = true;
        }
    }

    public function cancelPromote(): void
    {
        $this->promoteKind = '';
        $this->promoteType = '';
        $this->promoteId = null;
        $this->reset(['pTrigger', 'pResponse', 'pTitle', 'pContent']);
        $this->pTriggerType = 'contains';
        $this->pScope = 'contatos';
        $this->pAiMatch = true;
        $this->pSensitivity = 'medium';
        $this->pRestrict = true;
        $this->resetValidation();
    }

    /** Salva a regra pelo caminho OFICIAL (RuleWriter — mesmas guardas do /regras). */
    public function confirmPromoteRule(RuleWriter $writer, SecretVault $vault, RuleConflictDetector $detector): void
    {
        $fonte = $this->promoteSource($this->promoteType, $this->promoteId);
        if ($fonte === null || $fonte->isPromoted()) {
            $this->cancelPromote();

            return;
        }

        $trigger = trim($this->pTrigger);
        $resposta = trim($this->pResponse);
        if ($trigger === '') {
            $this->addError('pTrigger', 'Informe o gatilho.');

            return;
        }
        if ($resposta === '') {
            $this->addError('pResponse', 'Informe a resposta.');

            return;
        }

        // Guarda dura (espelha S5, com mensagem no campo certo do modal): resposta
        // com {senha:} NUNCA pode virar regra global.
        if ($this->pScope === 'global' && $vault->hasRef($resposta)) {
            $this->addError('pScope', 'Resposta com {senha:...} nao pode ser global. Mantenha "So este contato" — a senha iria em texto pra QUALQUER contato que disparasse.');

            return;
        }

        $contactIds = [];
        if ($this->pScope !== 'global') {
            if (! $fonte->contact_id) {
                $this->addError('pScope', 'Contato da pendencia nao existe mais. Crie a regra em /regras escolhendo os contatos.');

                return;
            }
            $contactIds = [(int) $fonte->contact_id];
        }

        $mensagem = trim((string) ($fonte->incomingMessage?->text ?? ''));

        $res = $writer->save($this->accountId(), [
            'triggers' => [['type' => in_array($this->pTriggerType, ['contains', 'exact', 'starts_with'], true) ? $this->pTriggerType : 'contains', 'value' => $trigger, 'precision' => 'exato']],
            'responses' => [$resposta],
            'enabled' => true,
            'cooldown_mode' => 'global',
            'cooldown_minutes' => null,
            'scope' => $this->pScope === 'global' ? 'global' : 'contatos',
            'contact_ids' => $contactIds,
            'ai_match_enabled' => $this->pAiMatch,
            // O aprendizado alimenta o casamento por IA: a mensagem original vira
            // a primeira frase-exemplo da intencao.
            'ai_examples' => ($this->pAiMatch && $mensagem !== '') ? [$mensagem] : [],
        ]);

        if ($res['errors'] !== []) {
            $mapa = ['scope' => 'pScope', 'scopeContactIds' => 'pScope', 'responses' => 'pResponse', 'triggers' => 'pTrigger', 'triggers.0.value' => 'pTrigger'];
            foreach ($res['errors'] as $campo => $msg) {
                $this->addError($mapa[$campo] ?? 'pTrigger', $msg);
            }

            return;
        }

        $rule = $res['rule'];
        $fonte->update(['promoted_rule_id' => $rule->id]);
        $this->cancelPromote();

        // Detector de conflito (aviso, nao bloqueio — como hoje no /regras).
        $conflitos = $detector->conflicts($this->accountId())[$rule->id] ?? [];
        if ($conflitos !== []) {
            $rotulos = collect($conflitos)->pluck('label')->unique()->take(3)->implode(', ');
            $this->dispatch('toast', message: "Regra #{$rule->id} criada. Atencao: sobreposicao com \"{$rotulos}\" — a mais especifica vence; confira em /regras.", type: 'error');

            return;
        }

        $this->dispatch('toast', message: "Regra #{$rule->id} criada. Da proxima vez a resposta e deterministica (sem IA).");
    }

    /** Salva a entrada pelo caminho OFICIAL (KnowledgeWriter — mesmas guardas). */
    public function confirmPromoteKnowledge(KnowledgeWriter $writer, SecretVault $vault): void
    {
        $fonte = $this->promoteSource($this->promoteType, $this->promoteId);
        if ($fonte === null || $fonte->isPromoted()) {
            $this->cancelPromote();

            return;
        }

        $titulo = trim($this->pTitle);
        $conteudo = trim($this->pContent);
        if ($titulo === '') {
            $this->addError('pTitle', 'Informe o titulo.');

            return;
        }
        if ($conteudo === '') {
            $this->addError('pContent', 'Informe o conteudo.');

            return;
        }

        // Guarda de segredo (coerente com a Fatia 2): {senha:} exige restringir
        // ao contato — sem contato valido, nao ha como promover com senha.
        $contactIds = ($this->pRestrict && $fonte->contact_id) ? [(int) $fonte->contact_id] : [];
        if ($vault->hasRef($conteudo) && $contactIds === []) {
            $this->addError('pRestrict', 'Conteudo com {senha:...} exige restringir ao contato. Sem restricao, a referencia valeria pra qualquer contato com IA.');

            return;
        }

        $res = $writer->save($this->accountId(), [
            'title' => $titulo,
            'content' => $conteudo,
            'sensitivity' => in_array($this->pSensitivity, \App\Models\Knowledge::SENSITIVITIES, true) ? $this->pSensitivity : 'medium',
            'active' => true,
            'contact_ids' => $contactIds,
        ]);

        if ($res['errors'] !== []) {
            $mapa = ['contactIds' => 'pRestrict', 'title' => 'pTitle', 'content' => 'pContent'];
            foreach ($res['errors'] as $campo => $msg) {
                $this->addError($mapa[$campo] ?? 'pContent', $msg);
            }

            return;
        }

        $fonte->update(['promoted_knowledge_id' => $res['knowledge']->id]);
        $this->cancelPromote();
        $this->dispatch('toast', message: 'Entrada criada na base de conhecimento.');
    }

    /**
     * Fonte da promocao, SEMPRE escopada pela conta. Pendencia: qualquer status.
     * Decisao: so onde a IA respondeu sozinha (escaladas ja tem pendencia propria).
     */
    private function promoteSource(string $type, ?int $id): PendingApproval|AiDecision|null
    {
        if ($id === null) {
            return null;
        }
        if ($type === 'pendencia') {
            return $this->find($id);
        }
        if ($type === 'decisao') {
            return AiDecision::query()->where('account_id', $this->accountId())
                ->where('acao', 'respondeu')->with('incomingMessage')->find($id);
        }

        return null;
    }

    /** Melhor texto de resposta disponivel pra pre-preencher a promocao. */
    private function promoteResponseFor(PendingApproval|AiDecision $fonte): string
    {
        if ($fonte instanceof PendingApproval) {
            return (string) $fonte->suggested_response;
        }

        // Decisao 'respondeu': o texto que FOI enviado (log do envio; respostas
        // automaticas nunca contem senha — guarda dura das Fatias 1-2).
        $enviado = $fonte->incoming_message_id
            ? AutoReplyLog::query()->where('incoming_message_id', $fonte->incoming_message_id)
                ->where('status', 'sent')->value('response_text')
            : null;

        return (string) ($enviado ?? $fonte->resposta_resumo ?? '');
    }

    // ---- consulta ---------------------------------------------------------------

    /** Sempre escopado pela conta — pendencia de outra conta e invisivel/inacionavel. */
    private function find(?int $id): ?PendingApproval
    {
        return $id
            ? PendingApproval::query()->where('account_id', $this->accountId())->find($id)
            : null;
    }

    private function accountId(): int
    {
        // MT-0: conta do CONTEXTO (fase 1 = conta unica, fallback centralizado).
        return app(\App\Tenancy\AccountContext::class)->id();
    }

    public function render()
    {
        $base = PendingApproval::query()
            ->where('account_id', $this->accountId())
            ->with(['contact:id,push_name,remote_jid', 'incomingMessage:id,text,push_name,received_at,channel_id'])
            ->latest('id');

        $itens = match ($this->filter) {
            'pendentes' => $base->where('status', 'pending')
                // Nao mostra velhas mesmo se a marcacao lazy ainda nao rodou.
                ->when((int) config('ai.approval_expire_days', 7) > 0,
                    fn ($q) => $q->where('created_at', '>=', now()->subDays((int) config('ai.approval_expire_days', 7))))
                ->limit(100)->get(),
            'decididas' => $base->whereIn('status', ['approved', 'edited', 'rejected'])->limit(100)->get(),
            'expiradas' => $base->where('status', 'expired')->limit(100)->get(),
            default => collect(),
        };

        // Aba de auditoria: decisoes recentes da IA (somente leitura; resumo ja redigido).
        $decisoes = $this->filter === 'decisoes'
            ? AiDecision::query()->where('account_id', $this->accountId())
                ->with('contact:id,push_name,remote_jid')
                ->latest('id')->limit(50)->get()
            : collect();

        $editing = $this->editingId ? $this->find($this->editingId) : null;
        $sending = $this->confirmingSendId ? $this->find($this->confirmingSendId) : null;

        // CH-2 — countdown da janela de 24h POR PENDENCIA, so quando o canal de
        // ENTRADA nao permite mensagem livre fora da janela (cloud_api). null =
        // canal livre (Evolution) — nada e mostrado.
        $janelas = [];
        if ($this->filter === 'pendentes') {
            $registry = app(\App\Channels\ProviderRegistry::class);
            foreach ($itens as $p) {
                $canal = $p->incomingMessage?->channel_id
                    ? \App\Models\Channel::query()->find($p->incomingMessage->channel_id)
                    : null;
                if ($canal === null || $registry->for($canal)->capabilities()->mensagemLivreForaDaJanela) {
                    continue;
                }
                $resta = \App\Models\ContactChannelWindow::restante((int) $p->account_id, (string) $p->remote_jid, (int) $canal->id);
                $janelas[$p->id] = $resta !== null
                    ? sprintf('%dh %02dmin', $resta->h + $resta->days * 24, $resta->i)
                    : 'FECHADA';
            }
        }

        return view('livewire.revisao', [
            'itens' => $itens,
            'decisoes' => $decisoes,
            'janelas' => $janelas,
            'editing' => $editing,
            'sending' => $sending,
            'vault' => app(SecretVault::class),
        ]);
    }
}
