<?php

namespace App\Jobs;

use App\Contracts\WhatsappGateway;
use App\Models\Account;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\IncomingMessage;
use App\Whatsapp\AutoReply\AntiBanGuard;
use App\Whatsapp\AutoReply\RuleMatcher;
use App\Whatsapp\IncomingMessageData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Recebe (Camada 1): normaliza + persiste com idempotencia.
 * Liga a auto-resposta (Camada 2 Fatia 3): popula a agenda de contatos e, se um
 * contato APROVADO casa uma regra, enfileira a resposta com delay humano.
 *
 * DORMENTE por padrao: kill switch OFF + politica allowlist (contato novo entra como
 * 'default' -> nao responde ate o Fabio aprovar e ligar o kill switch). Tudo na fila.
 *
 * auto_reply_logs registra as TENTATIVAS de resposta (contato aprovado + regra casou):
 * o resultado/freio (kill switch, janela, rate, tetos) sai do Sender. Silencios
 * estruturais (fromMe, grupo, sem regra, contato nao-aprovado) NAO geram log.
 */
class ProcessIncomingWhatsappMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly array $payload,
    ) {
    }

    public function handle(WhatsappGateway $gateway, RuleMatcher $matcher, AntiBanGuard $guard): void
    {
        $data = $gateway->normalizeIncoming($this->payload);

        // Evento que nao e mensagem (ou sem id) — nada a registrar.
        if ($data === null) {
            return;
        }

        $account = $this->resolverAccount();
        $channel = $this->resolverChannel($account, $data);

        $channel->forceFill(['last_event_at' => now()])->save();

        $message = $this->persistir($account, $channel, $data);

        // Re-entrega duplicada — ja tratada, nao reavalia.
        if ($message === null) {
            return;
        }

        $this->popularContato($account, $data, $guard);
        $this->avaliarAutoResposta($account, $channel, $message, $data, $matcher, $guard);
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

    private function persistir(Account $account, Channel $channel, IncomingMessageData $data): ?IncomingMessage
    {
        try {
            return IncomingMessage::create([
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
            return null;
        }
    }

    /** Agenda automatica: cada mensagem individual RECEBIDA cria/atualiza o contato. */
    private function popularContato(Account $account, IncomingMessageData $data, AntiBanGuard $guard): void
    {
        if ($data->fromMe || $guard->isGroup($data->remoteJid)) {
            return;
        }

        $contact = Contact::firstOrNew([
            'account_id' => $account->id,
            'remote_jid' => $data->remoteJid,
        ]);

        if (! $contact->exists) {
            // Contato novo entra sob allowlist como 'default' -> nao responde ate aprovar.
            $contact->auto_reply_mode = 'default';
        }

        if ($data->pushName) {
            $contact->push_name = $data->pushName;
        }

        $contact->save();
    }

    private function avaliarAutoResposta(
        Account $account,
        Channel $channel,
        IncomingMessage $message,
        IncomingMessageData $data,
        RuleMatcher $matcher,
        AntiBanGuard $guard,
    ): void {
        // Guarda fromMe (anti-loop) e pula grupos.
        if ($data->fromMe || $guard->isGroup($data->remoteJid)) {
            return;
        }

        // Sem regra que case -> silencio. (Passa o remetente p/ o escopo por contato — S3.)
        $rule = $matcher->match($account->id, $channel->id, $data->text, $data->remoteJid);
        if ($rule === null) {
            return;
        }

        // Portao de contato (allowlist/all + auto_reply_mode) -> silencio se nao aprovado.
        if (! $guard->contactGatePasses($account->id, $data->remoteJid)) {
            return;
        }

        // Delay humano: a auto-resposta vai pra fila com atraso aleatorio. O envio real
        // (e o re-check R2 + freios volateis) acontece no SendAutoReply via Sender.
        // S7: a resposta (escolha aleatoria + placeholders) e resolvida NO ENVIO, nao
        // aqui — por isso passamos so o rule->id (sem texto).
        $settings = $guard->settingsFor($account->id);
        $min = (int) $settings->delay_min_seconds;
        $max = (int) max($min, $settings->delay_max_seconds);

        SendAutoReply::dispatch($message->id, $rule->id)
            ->delay(now()->addSeconds(random_int($min, $max)));
    }
}
