<?php

namespace App\Livewire;

use App\Models\Account;
use App\Models\AiDecision;
use App\Models\PendingApproval;
use App\Whatsapp\AutoReply\RuleResponder;
use App\Whatsapp\AutoReply\Sender;
use App\Whatsapp\Secrets\SecretVault;
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
        return (int) (Account::query()->oldest('id')->value('id')
            ?? Account::create(['name' => config('app.name', 'msgautomation')])->id);
    }

    public function render()
    {
        $base = PendingApproval::query()
            ->where('account_id', $this->accountId())
            ->with(['contact:id,push_name,remote_jid', 'incomingMessage:id,text,push_name,received_at'])
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

        return view('livewire.revisao', [
            'itens' => $itens,
            'decisoes' => $decisoes,
            'editing' => $editing,
            'sending' => $sending,
            'vault' => app(SecretVault::class),
        ]);
    }
}
