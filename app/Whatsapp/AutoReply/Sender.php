<?php

namespace App\Whatsapp\AutoReply;

use App\Contracts\WhatsappGateway;
use App\Models\AutoReplyLog;
use App\Models\Channel;
use App\Whatsapp\Exceptions\WhatsappSendException;
use App\Whatsapp\Secrets\SecretMissingException;
use App\Whatsapp\Secrets\SecretVault;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Orquestra o envio aplicando os freios e registrando tudo em auto_reply_logs.
 *
 * Fluxo:
 *  1. cria/claim a linha de log (status 'pending'). Para 'auto' com incoming_message_id,
 *     o indice unico garante UMA resposta por mensagem recebida (idempotencia).
 *  2. roda os freios (R1: manual so tetos; auto tudo).
 *  3. R2: re-checa kill switch + opt-out + janela imediatamente antes do POST (auto).
 *  4. envia pelo driver; em sucesso registra contadores e marca a linha 'sent'.
 */
class Sender
{
    public function __construct(
        private WhatsappGateway $gateway,
        private AntiBanGuard $guard,
        private Throttle $throttle,
        private SecretVault $vault,
    ) {
    }

    public function send(
        string $mode,
        Channel $channel,
        string $jid,
        string $text,
        ?int $incomingMessageId = null,
        ?int $ruleId = null,
        bool $fromMe = false,
        bool $flow = false,
    ): AutoReplyLog {
        $accountId = $channel->account_id;

        // 1. claim/log. O claim por incoming_message_id vale pro 'auto' E pro
        //    'aprovacao' (Fatia 3): UMA resposta por mensagem recebida, mesmo com
        //    duplo clique/corrida entre robo e humano.
        if (in_array($mode, ['auto', 'aprovacao'], true) && $incomingMessageId !== null) {
            try {
                $log = AutoReplyLog::create($this->base($accountId, $channel, $jid, $text, $mode, $incomingMessageId, $ruleId));
            } catch (UniqueConstraintViolationException) {
                // Ja existe resposta pra essa mensagem recebida -> nao reenvia.
                return AutoReplyLog::where('incoming_message_id', $incomingMessageId)->first();
            }
        } else {
            $log = AutoReplyLog::create($this->base($accountId, $channel, $jid, $text, $mode, null, $ruleId));
        }

        // 2. freios (ruleId habilita o cooldown por regra — S2; flow isenta o intervalo
        //    por contato durante a sessao — Fatia A)
        $decision = $this->guard->check($mode, $accountId, $jid, $fromMe, $ruleId, $flow);
        if (! $decision->allowed) {
            $log->update(['status' => 'blocked', 'motivo' => $decision->reason]);

            return $log;
        }

        // 3. R2 — re-check volatil antes do POST (so auto)
        if ($mode === 'auto') {
            $recheck = $this->guard->volatileRecheck($accountId, $jid);
            if (! $recheck->allowed) {
                $log->update(['status' => 'blocked', 'motivo' => $recheck->reason]);

                return $log;
            }
        }

        // 3b. R2 do envio APROVADO (Fatia 3): re-checa SO o opt-out antes do POST
        //     (o contato pode ter virado 'off' entre abrir a tela e clicar).
        if ($mode === 'aprovacao' && $this->guard->contactMode($accountId, $jid) === 'off') {
            $log->update(['status' => 'blocked', 'motivo' => 'opt_out']);

            return $log;
        }

        // 3.5 — resolve {senha:nome} EM MEMORIA, so agora (no envio). O log ja guarda a
        // versao REDIGIDA (ver base()); o plaintext nunca e persistido nem logado.
        // Senha ausente -> falha controlada (nao envia meia-resposta).
        try {
            $textoEnvio = $this->vault->hasRef($text) ? $this->vault->resolve($accountId, $text) : $text;
        } catch (SecretMissingException) {
            $log->update(['status' => 'failed', 'motivo' => 'senha_ausente']);

            return $log;
        }

        // 4. envio (usa o texto resolvido; descartado apos o POST)
        try {
            $sent = $this->gateway->sendText($channel->instance, $jid, $textoEnvio);
        } catch (WhatsappSendException) {
            $log->update(['status' => 'failed', 'motivo' => 'erro_envio']);

            return $log;
        }
        unset($textoEnvio);

        $this->throttle->recordSend($accountId);
        if ($mode === 'auto') {
            $this->throttle->markContactReplied($accountId, $jid, $this->guard->settingsFor($accountId)->contact_rate_seconds);
        }

        $log->update([
            'status' => 'sent',
            'provider_message_id' => $sent->providerMessageId,
            'sent_at' => now(),
        ]);

        return $log;
    }

    private function base(int $accountId, Channel $channel, string $jid, string $text, string $mode, ?int $incomingMessageId, ?int $ruleId): array
    {
        return [
            'account_id' => $accountId,
            'channel_id' => $channel->id,
            'incoming_message_id' => $incomingMessageId,
            'rule_id' => $ruleId,
            'remote_jid' => $jid,
            'mode' => $mode,
            // S4: o log guarda a REDACAO ({senha:nome} -> [senha: nome]); nunca o valor.
            'response_text' => $this->vault->redact($text),
            'status' => 'pending',
        ];
    }
}
