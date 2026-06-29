<?php

namespace App\Jobs;

use App\Models\AutoReplyRule;
use App\Models\IncomingMessage;
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
     */
    public function __construct(
        public readonly int $incomingMessageId,
        public readonly ?int $ruleId,
        public readonly ?string $text = null,
        public readonly bool $flow = false,
    ) {
    }

    public function handle(Sender $sender, RuleResponder $responder): void
    {
        $incoming = IncomingMessage::with('channel')->find($this->incomingMessageId);

        if (! $incoming || ! $incoming->channel) {
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
