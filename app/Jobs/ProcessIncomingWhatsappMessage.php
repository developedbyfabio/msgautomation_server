<?php

namespace App\Jobs;

use App\Contracts\WhatsappGateway;
use App\Models\Account;
use App\Models\Channel;
use App\Models\IncomingMessage;
use App\Whatsapp\IncomingMessageData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Normaliza o payload do webhook (via driver) e persiste em incoming_messages
 * com idempotencia. Roda FORA do request (fila) — o webhook so enfileira.
 *
 * Camada 1: somente RECEBER e REGISTRAR. Nao responde, nao envia, sem IA.
 */
class ProcessIncomingWhatsappMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly array $payload,
    ) {
    }

    public function handle(WhatsappGateway $gateway): void
    {
        $data = $gateway->normalizeIncoming($this->payload);

        // Evento que nao e mensagem (ou sem id) — nada a registrar.
        if ($data === null) {
            return;
        }

        $account = $this->resolverAccount();
        $channel = $this->resolverChannel($account, $data);

        $channel->forceFill(['last_event_at' => now()])->save();

        $this->persistir($account, $channel, $data);
    }

    private function resolverAccount(): Account
    {
        // Single-user na Camada 1: uma unica linha-ancora.
        return Account::query()->oldest('id')->first()
            ?? Account::create(['name' => config('app.name', 'msgautomation')]);
    }

    private function resolverChannel(Account $account, IncomingMessageData $data): Channel
    {
        return Channel::firstOrCreate(
            ['instance' => $data->instance],
            ['account_id' => $account->id, 'status' => 'connected'],
        );
    }

    private function persistir(Account $account, Channel $channel, IncomingMessageData $data): void
    {
        try {
            IncomingMessage::create([
                'account_id' => $account->id,
                'channel_id' => $channel->id,
                'instance' => $data->instance,
                'evolution_message_id' => $data->evolutionMessageId,
                'remote_jid' => $data->remoteJid,
                'from_me' => $data->fromMe,
                'push_name' => $data->pushName,
                'type' => $data->type,
                'text' => $data->text,
                'raw_payload' => $data->raw,
                'received_at' => $data->receivedAt,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Re-entrega do webhook (mesmo instance + evolution_message_id): ignora.
            // A idempotencia e garantida pelo indice unico no banco.
        }
    }
}
