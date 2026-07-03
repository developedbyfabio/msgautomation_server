<?php

namespace App\Whatsapp\AutoReply;

use App\Channels\ProviderRegistry;
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
        private ProviderRegistry $providers,
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
        ?int $campaignId = null,
        ?array $media = null, // Prompts 04/05: ['kind' => image|document, 'path' => relativo ao disco local, 'mime', 'name' => nome original]
    ): AutoReplyLog {
        $accountId = $channel->account_id;

        // 1. claim/log. O claim por incoming_message_id vale pro 'auto' E pro
        //    'aprovacao' (Fatia 3): UMA resposta por mensagem recebida, mesmo com
        //    duplo clique/corrida entre robo e humano. 'proactive' (P-3) nao tem
        //    incoming (INICIA conversa): a idempotencia e do claim do TARGET.
        if (in_array($mode, ['auto', 'aprovacao'], true) && $incomingMessageId !== null) {
            try {
                $log = AutoReplyLog::create($this->base($accountId, $channel, $jid, $text, $mode, $incomingMessageId, $ruleId, $campaignId, $media));
            } catch (UniqueConstraintViolationException) {
                // Ja existe resposta pra essa mensagem recebida -> nao reenvia.
                return AutoReplyLog::where('incoming_message_id', $incomingMessageId)->first();
            }
        } else {
            $log = AutoReplyLog::create($this->base($accountId, $channel, $jid, $text, $mode, null, $ruleId, $campaignId, $media));
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

        // 3c. R2 PROATIVO (P-3): re-executa as condicoes VOLATEIS da jaula (switch
        //     proprio, opt-out/opt-in do contato, janela, segredo) no INSTANTE do
        //     POST. Quem chamou (job) devolve o claim ao ver 'blocked' aqui.
        if ($mode === 'proactive') {
            $recheck = app(\App\Whatsapp\Proactive\ProactiveGuard::class)
                ->volatileRecheck($accountId, $jid, $text);
            if (! $recheck->allowed) {
                $log->update(['status' => 'blocked', 'motivo' => $recheck->reason]);

                return $log;
            }
        }

        // 3d. CH-2 — janela de 24h REAL por contato+CANAL: provedor sem mensagem
        //     livre fora da janela (cloud_api) BLOQUEIA quando o ultimo inbound
        //     NESTE canal passou de 24h. Reativo (auto) responde segundos apos o
        //     inbound — janela aberta por construcao; a checagem cobre o caso
        //     patologico de fila represada por 24h+ (Parte B: fora da janela nao
        //     tenta free-form). Evolution declara TRUE — nem consulta.
        if (in_array($mode, ['auto', 'manual', 'aprovacao'], true)
            && ! $this->providers->for($channel)->capabilities()->mensagemLivreForaDaJanela
            && ! \App\Models\ContactChannelWindow::isOpen($accountId, $jid, (int) $channel->id)) {
            $log->update(['status' => 'blocked', 'motivo' => 'janela_24h']);

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

        // 4. envio (usa o texto resolvido; descartado apos o POST) — CH-1: pelo
        //    provider DO CANAL (credenciais do canal com fallback no env).
        //    CH-2 Parte B: com incoming conhecido, passa o providerMessageId dele
        //    (wamid no cloud) pro provider fazer reply CONTEXTUAL; a Evolution ignora.
        $replyTo = $incomingMessageId !== null
            ? \App\Models\IncomingMessage::withoutAccountScope()->whereKey($incomingMessageId)->value('evolution_message_id')
            : null;
        //    Prompts 04/05: com anexo, o transporte e sendImage/sendDocument
        //    (caption = texto resolvido); MESMOS freios/janela/log — anexo nao
        //    fura teto.
        try {
            if ($media !== null) {
                $provider = $this->providers->for($channel);
                $absoluto = \Illuminate\Support\Facades\Storage::disk('local')->path($media['path']);
                $caption = $textoEnvio !== '' ? $textoEnvio : null;
                $sent = ($media['kind'] ?? 'image') === 'document'
                    ? $provider->sendDocument($channel, $jid, $absoluto, (string) $media['mime'], (string) ($media['name'] ?? basename($absoluto)), $caption, $replyTo)
                    : $provider->sendImage($channel, $jid, $absoluto, (string) $media['mime'], $caption, $replyTo);
            } else {
                $sent = $this->providers->for($channel)->sendText($channel, $jid, $textoEnvio, $replyTo);
            }
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

        // Kanban K-1 — evento de dominio no ENVIO EFETIVO (listener em fila;
        // observador puro — nada aqui altera o envio ja concluido).
        event($mode === 'manual'
            ? new \App\Events\ManualMessageSent((int) $accountId, (int) $log->id, $jid)
            : new \App\Events\AutoReplySent((int) $accountId, (int) $log->id, $jid, $mode));

        return $log;
    }

    private function base(int $accountId, Channel $channel, string $jid, string $text, string $mode, ?int $incomingMessageId, ?int $ruleId, ?int $campaignId = null, ?array $media = null): array
    {
        return [
            'media_path' => $media['path'] ?? null,  // Prompt 04
            'media_mime' => $media['mime'] ?? null,
            'media_name' => $media['name'] ?? null,  // Prompt 05
            'account_id' => $accountId,
            'channel_id' => $channel->id,
            'incoming_message_id' => $incomingMessageId,
            'rule_id' => $ruleId,
            'campaign_id' => $campaignId, // P-3: origem proactive rastreada
            'remote_jid' => $jid,
            'mode' => $mode,
            // S4: o log guarda a REDACAO ({senha:nome} -> [senha: nome]); nunca o valor.
            'response_text' => $this->vault->redact($text),
            'status' => 'pending',
        ];
    }
}
