<?php

namespace App\Jobs;

use App\Models\AutoReplyRule;
use App\Models\IncomingMessage;
use App\Tenancy\AccountContext;
use App\Whatsapp\AutoReply\RuleResponder;
use App\Whatsapp\AutoReply\Sender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Job de AUTO-resposta. Implementado e testavel nesta fatia, mas AINDA NAO ligado ao
 * recebimento (isso e a Fatia 3, que vai despachar este job com ->delay() humano).
 *
 * O envio passa pelo Sender (todos os freios + R2 re-check antes do POST).
 */
class SendAutoReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * $text opcional/legado: se vier preenchido, e usado direto. Se vier null
     * (caminho S7), a resposta e resolvida NO ENVIO via RuleResponder (escolha
     * aleatoria entre as respostas da regra + placeholders).
     * $accountId (MT-0): contexto serializado; null (job antigo na fila durante o
     * deploy) -> resolvido do proprio incoming via bypass nomeado.
     */
    public function __construct(
        public readonly int $incomingMessageId,
        public readonly ?int $ruleId,
        public readonly ?string $text = null,
        public readonly bool $flow = false,
        public readonly ?int $accountId = null,
    ) {
    }

    public function handle(Sender $sender, RuleResponder $responder): void
    {
        // MT-0: acha a mensagem SEM escopo (unico bypass do job) e restaura o
        // contexto ANTES de qualquer outra query (channel lazy ja escopado certo).
        $incoming = IncomingMessage::withoutAccountScope()->find($this->incomingMessageId);

        if (! $incoming) {
            return;
        }

        $aid = (isset($this->accountId) ? $this->accountId : null) ?? (int) $incoming->account_id;
        app(AccountContext::class)->set($aid);

        if (! $incoming->channel) {
            return;
        }

        $text = $this->text;

        // S7 — resolve a resposta no envio (depois do delay): escolha aleatoria +
        // placeholders ({nome}, saudacao por horario, ...).
        if ($text === null && $this->ruleId !== null) {
            $rule = AutoReplyRule::with('responses')->find($this->ruleId);
            if ($rule) {
                $text = $responder->responseFor($rule, [
                    'nome' => $incoming->push_name,
                    'now' => now(),
                ]);
            }
        } elseif ($text !== null) {
            // BUGFIX: texto DIRETO (no de fluxo, resposta da base da IA) passa pelo
            // MESMO renderizador das regras NO ENVIO — antes um no com "{saudacao}"
            // saia cru pro contato. Render toca so {nome}/{saudacao}/{data}/{hora};
            // {senha:} fica intacto e continua sendo resolvido SO no POST (Sender),
            // com a guarda de fluxo-com-senha existente valendo como sempre.
            $text = $responder->render($text, [
                'nome' => $incoming->push_name,
                'now' => now(),
            ]);
        }

        // Sem resposta resolvida -> nada a enviar (nao gera log de envio).
        if ($text === null || $text === '') {
            return;
        }

        $sender->send(
            mode: 'auto',
            channel: $incoming->channel,
            jid: $incoming->remote_jid,
            text: $text,
            incomingMessageId: $incoming->id,
            ruleId: $this->ruleId,
            fromMe: (bool) $incoming->from_me,
            flow: $this->flow,
        );
    }
}
