<?php

namespace App\Jobs;

use App\Models\IncomingMessage;
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

    public function __construct(
        public readonly int $incomingMessageId,
        public readonly ?int $ruleId,
        public readonly string $text,
    ) {
    }

    public function handle(Sender $sender): void
    {
        $incoming = IncomingMessage::with('channel')->find($this->incomingMessageId);

        if (! $incoming || ! $incoming->channel) {
            return;
        }

        $sender->send(
            mode: 'auto',
            channel: $incoming->channel,
            jid: $incoming->remote_jid,
            text: $this->text,
            incomingMessageId: $incoming->id,
            ruleId: $this->ruleId,
            fromMe: (bool) $incoming->from_me,
        );
    }
}
