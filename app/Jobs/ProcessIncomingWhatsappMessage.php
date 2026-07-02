<?php

namespace App\Jobs;

use App\Contracts\WhatsappGateway;
use App\Models\Account;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\IncomingMessage;
use App\Tenancy\AccountContext;
use App\Whatsapp\AutoReply\AntiBanGuard;
use App\Whatsapp\AutoReply\RuleMatcher;
use App\Whatsapp\IncomingMessageData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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

        // MT-0 (L1): a CONTA vem do CANAL da instancia do payload — unico lookup
        // legitimamente cross-account do pipeline (bypass NOMEADO). Instancia
        // desconhecida: loga + conta no cache (diagnostico) e DESCARTA com
        // seguranca — NUNCA cai em outra conta.
        $channel = Channel::withoutAccountScope()->where('instance', $data->instance)->first();
        if ($channel === null) {
            Log::warning('Webhook: instancia desconhecida — payload descartado.', ['instance' => $data->instance]);
            Cache::increment('webhook:instancia_desconhecida:' . now()->format('Y-m-d'));
            Cache::put('webhook:instancia_desconhecida:ultima', $data->instance, now()->addDays(7));

            return;
        }

        // Contexto de conta EXPLICITO pro resto do job (queries escopadas).
        app(AccountContext::class)->set((int) $channel->account_id);
        $account = $channel->account;

        $channel->forceFill(['last_event_at' => now()])->save();

        $message = $this->persistir($account, $channel, $data);

        // Re-entrega duplicada — ja tratada, nao reavalia.
        if ($message === null) {
            return;
        }

        $contato = $this->popularContato($account, $data, $guard);

        // Kanban K-1 — evento de dominio (listener em fila; observador puro).
        // So mensagens INDIVIDUAIS recebidas (popularContato ja exclui fromMe/grupo).
        if ($contato !== null) {
            event(new \App\Events\IncomingMessageStored(
                (int) $account->id, (int) $message->id, (int) $contato->id, (string) $data->remoteJid,
            ));

            // Proativas P-1 — opt-out por PALAVRA: revoga o opt-in e registra a
            // trilha. NAO responde nada e NAO interfere no resto do pipeline (a
            // mensagem segue casando regra/fluxo como qualquer outra).
            $this->detectarOptOutProativo($account, $contato, $matcher, (string) $data->text);
        }

        $this->avaliarAutoResposta($account, $channel, $message, $data, $matcher, $guard);
    }

    /**
     * P-1 — palavra de opt-out (match EXATO, case/acento-insensivel via a mesma
     * normalizacao do matcher). So age se o contato TEM opt-in (sem opt-in = no-op,
     * sem log falso de revogacao). A trilha em proactive_consents nunca e apagada.
     */
    private function detectarOptOutProativo(Account $account, Contact $contato, RuleMatcher $matcher, string $texto): void
    {
        if (! $contato->proactive_opt_in) {
            return;
        }

        $palavra = (string) ($this->settingsDe($account)->proactive_optout_word ?: 'PARAR');
        if ($palavra === '' || $matcher->normalize($texto) !== $matcher->normalize($palavra)) {
            return;
        }

        $contato->update(['proactive_opt_in' => false]);
        \App\Models\ProactiveConsent::create([
            'account_id' => $account->id,
            'contact_id' => $contato->id,
            'action' => 'revoke',
            'origin' => 'palavra',
        ]);

        // P-3: revogou = pula o contato em TODAS as campanhas (targets pendentes).
        \App\Models\CampaignTarget::skipAllPendingFor((int) $account->id, (int) $contato->id, 'opt_out_revogado');
    }

    private function settingsDe(Account $account): \App\Models\AutoReplySetting
    {
        return \App\Models\AutoReplySetting::firstOrCreate(['account_id' => $account->id]);
    }

    private function persistir(Account $account, Channel $channel, IncomingMessageData $data): ?IncomingMessage
    {
        try {
            return IncomingMessage::create([
                'account_id' => $account->id,
                'channel_id' => $channel->id,
                'instance' => $data->instance,
                'evolution_message_id' => $data->providerMessageId, // CH-D4: coluna legada, DTO neutro
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

    /**
     * Agenda automatica: cada mensagem individual RECEBIDA cria/atualiza o contato.
     * Retorna o contato (null pra fromMe/grupo — que tambem ficam fora do Kanban).
     */
    private function popularContato(Account $account, IncomingMessageData $data, AntiBanGuard $guard): ?Contact
    {
        if ($data->fromMe || $guard->isGroup($data->remoteJid)) {
            return null;
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

        return $contact;
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

        $jid = $data->remoteJid;
        $flows = app(\App\Whatsapp\Flows\FlowEngine::class);

        // Fatia A — (1) sessao de fluxo ATIVA tem prioridade: navegacao, nunca cai nas regras.
        $session = $flows->activeSession($account->id, $jid);
        if ($session !== null) {
            // Opt-out no meio do fluxo -> encerra a sessao e silencia (decisao 7).
            if ($guard->contactMode($account->id, $jid) === 'off') {
                $session->update(['status' => 'cancelled']);

                return;
            }
            $this->dispatchFlowReply($account, $channel, $message, $flows->advance($session, (string) $data->text), $guard);

            return;
        }

        // (2) Sem sessao: fluxo de ENTRADA vence a regra (decisao 6). Exige aprovacao do contato.
        $flow = $flows->entryFlow($account->id, (string) $data->text, $jid);
        if ($flow !== null) {
            if (! $guard->contactGatePasses($account->id, $jid)) {
                return;
            }
            $this->dispatchFlowReply($account, $channel, $message, $flows->start($account->id, $flow, $jid), $guard);

            return;
        }

        // (3) Regras normais (inalterado). Sem regra que case -> IA (fallback) ou silencio.
        $rule = $matcher->match($account->id, $channel->id, $data->text, $jid);
        if ($rule === null) {
            // (4) Camada 3 — FALLBACK: nada casou. Se a IA esta elegivel pro contato
            // (kill switch da IA ON + IA ligada no contato + portao passa), classifica
            // em job SEPARADO (a API tem latencia/429; nao trava o pipeline). Tudo OFF
            // por padrao -> este ramo nao dispara ate o Fabio ligar a IA.
            if ($guard->aiEligible($account->id, $jid)) {
                // MT-0: account_id serializado — o job restaura o contexto no handle.
                ClassifyWithAi::dispatch($message->id, $account->id);
            }

            return;
        }

        // Portao de contato (allowlist/all + auto_reply_mode) -> silencio se nao aprovado.
        if (! $guard->contactGatePasses($account->id, $jid)) {
            return;
        }

        // Delay humano: a auto-resposta vai pra fila com atraso aleatorio. O envio real
        // (e o re-check R2 + freios volateis) acontece no SendAutoReply via Sender.
        $settings = $guard->settingsFor($account->id);
        $min = (int) $settings->delay_min_seconds;
        $max = (int) max($min, $settings->delay_max_seconds);

        SendAutoReply::dispatch($message->id, $rule->id, accountId: $account->id)
            ->delay(now()->addSeconds(random_int($min, $max)));
    }

    /**
     * Enfileira a resposta de um nó de fluxo (texto resolvido no envio). `flow: true`
     * isenta o "Intervalo por contato"/cooldown durante a sessao (resto dos freios vale).
     */
    private function dispatchFlowReply(Account $account, Channel $channel, IncomingMessage $message, array $res, AntiBanGuard $guard): void
    {
        $text = $res['text'] ?? null;
        if ($text === null || $text === '') {
            return;
        }

        $settings = $guard->settingsFor($account->id);
        $min = (int) $settings->delay_min_seconds;
        $max = (int) max($min, $settings->delay_max_seconds);

        SendAutoReply::dispatch($message->id, null, $text, true, accountId: $account->id)
            ->delay(now()->addSeconds(random_int($min, $max)));
    }
}
