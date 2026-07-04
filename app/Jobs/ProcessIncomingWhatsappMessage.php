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
        public readonly ?int $channelId = null, // CH-2: hint da rota (provider do canal normaliza)
    ) {
    }

    public function handle(WhatsappGateway $gateway, RuleMatcher $matcher, AntiBanGuard $guard): void
    {
        // CH-2: com hint da rota, quem normaliza e o provider DO CANAL (Cloud API
        // adapta o payload da Meta). Sem hint (rota Evolution/legado/testes), o
        // alias WhatsappGateway segue identico — o canal vivo nao muda.
        $normalizador = $gateway;
        $canalHint = null;
        if ($this->channelId !== null) {
            $canalHint = Channel::withoutAccountScope()->find($this->channelId);
            if ($canalHint !== null) {
                $normalizador = app(\App\Channels\ProviderRegistry::class)->for($canalHint);
            }
        }
        $data = $normalizador->normalizeIncoming($this->payload);

        // Evento que nao e mensagem (ou sem id) — nada a registrar. EXCECAO
        // (Prompt 02): status FAILED da Meta agora e PERSISTIDO — foi o buraco
        // do 130497 (a Meta aceita o envio com wamid e descarta assincrono; o
        // "sent" do nosso log mentia). Statuses sent/delivered/read seguem
        // ignorados (D5).
        if ($data === null) {
            if ($canalHint?->provider === 'cloud_api') {
                $this->registrarFalhasDeStatus($canalHint, $this->payload);
            }

            return;
        }

        // Prompt 16 — REACAO (curtir/coracao/emoji) NAO e mensagem: choke point unico,
        // ANTES de criar IncomingMessage e ANTES de QUALQUER consumidor (popularContato,
        // evento/Kanban, RuleMatcher, aiEligible/ClassifyWithAi, metrica). Corte por TIPO
        // EXPLICITO (nunca por texto vazio — midia legitima tambem tem texto nulo).
        if (in_array($data->type, IncomingMessage::REACTION_TYPES, true)) {
            Log::debug('Reacao recebida descartada (nao vira mensagem).', [
                'type' => $data->type, 'instance' => $data->instance,
            ]);

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

        // CH-2 Parte B — dedup RAPIDO por wamid (a Meta entrega at-least-once e
        // re-tenta por 36h). Cache::add e atomico (SET NX no Redis); a chave so
        // encurta o caminho — quem GARANTE exactly-once no persist continua sendo
        // o indice unico (instance, evolution_message_id). Por isso o retorno
        // antecipado exige as DUAS provas (chave ja vista E mensagem no banco):
        // um retry do proprio job apos falha parcial nunca perde mensagem.
        if ($channel->provider === 'cloud_api') {
            $inedito = Cache::add('cloud:dedup:' . sha1($data->providerMessageId), 1, now()->addHours(48));
            if (! $inedito && IncomingMessage::withoutAccountScope()
                ->where('instance', $data->instance)
                ->where('evolution_message_id', $data->providerMessageId)->exists()) {
                Log::debug('Cloud API: re-entrega deduplicada por wamid.', ['channel' => $channel->id]);

                return;
            }
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

        // Prompt 13/14 — midia recebida: baixa/armazena em JOB SEPARADO (best-effort,
        // nao-bloqueante). O reativo abaixo NUNCA espera nem depende disto. Gate POR
        // CONTA (Prompt 14): a opcao da tela manda; sem opcao, cai no default do .env.
        if ($message->mediaCategory() !== null && $this->settingsDe($account)->mediaAutodownloadEnabled()) {
            DownloadIncomingMedia::dispatch((int) $message->id, (int) $account->id, (int) $channel->id);
        }

        $contato = $this->popularContato($account, $data, $guard);

        // Kanban K-1 — evento de dominio (listener em fila; observador puro).
        // So mensagens INDIVIDUAIS recebidas (popularContato ja exclui fromMe/grupo).
        if ($contato !== null) {
            // CH-2 — janela de 24h POR CONTATO+CANAL: todo inbound re-abre a
            // janela DESTE canal (a Evolution nem consulta; o cloud_api sim).
            \App\Models\ContactChannelWindow::touchWindow(
                (int) $account->id, (int) $contato->id, (int) $channel->id,
            );

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
     * Prompt 02 — statuses[] FAILED da Meta (assincronos, pos-aceite): marca o
     * envio correspondente como FALHO (por wamid) e grava evento com code/title
     * legiveis pro /logs. Idempotente por ref (re-entrega nao duplica). O 200
     * rapido do webhook nao muda (isto roda no worker).
     */
    private function registrarFalhasDeStatus(Channel $canal, array $payload): void
    {
        foreach ((array) data_get($payload, 'entry', []) as $entry) {
            foreach ((array) data_get($entry, 'changes', []) as $change) {
                foreach ((array) data_get($change, 'value.statuses', []) as $status) {
                    if (($status['status'] ?? '') !== 'failed') {
                        continue;
                    }

                    $wamid = (string) ($status['id'] ?? '');
                    $erro = (array) data_get($status, 'errors.0', []);
                    $code = (string) ($erro['code'] ?? '?');
                    $titulo = (string) ($erro['title'] ?? ($erro['message'] ?? 'falha sem descricao'));
                    $recipient = (string) ($status['recipient_id'] ?? '?');
                    $quando = is_numeric($status['timestamp'] ?? null)
                        ? \Illuminate\Support\Carbon::createFromTimestampUTC((int) $status['timestamp'])
                        : now();

                    if ($wamid !== '') {
                        \App\Models\AutoReplyLog::withoutAccountScope()
                            ->where('provider_message_id', $wamid)
                            ->where('status', 'sent')
                            ->update(['status' => 'failed', 'motivo' => 'meta_' . $code]);
                    }

                    try {
                        \App\Models\SystemEvent::withoutAccountScope()->firstOrCreate(
                            ['ref' => 'status-failed:' . ($wamid !== '' ? $wamid : md5(json_encode($status)))],
                            [
                                'account_id' => $canal->account_id,
                                'channel_id' => $canal->id,
                                'type' => 'envio_falhou',
                                'level' => 'error',
                                'title' => "Meta recusou o envio ({$code}): " . mb_substr($titulo, 0, 130),
                                'detail' => ['code' => $code, 'title' => $titulo, 'recipient_id' => $recipient, 'wamid' => $wamid],
                                'occurred_at' => $quando,
                            ],
                        );
                    } catch (\Throwable) {
                        // corrida entre re-entregas: o unique(ref) segura; segue.
                    }

                    Log::info('Cloud API: status FAILED persistido.', ['code' => $code, 'channel' => $canal->id]);
                }
            }
        }
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

        // (3) Regras normais (inalterado). Sem regra que case -> catch-all (auto) / IA / silencio.
        $rule = $matcher->match($account->id, $channel->id, $data->text, $jid);
        if ($rule === null) {
            // Fatia 4 — MODO AUTOMATICO: catch-all deterministico. SO dispara se
            // operation_mode=auto E ha fluxo padrao valido/habilitado E o gate passa
            // (politica efetiva 'all'; mute e tetos continuam valendo). Precede a IA
            // (porta deterministica). Sem fluxo valido/gate: fall-through IDENTICO ao
            // comportamento atual (degradacao graciosa). Settings da CONTA DO CANAL
            // do payload (ingestao), nunca AccountContext de request.
            $settings = $guard->settingsFor($account->id);
            if ($settings->operation_mode === \App\Enums\OperationMode::Auto) {
                $defaultFlow = $settings->defaultFlow; // FK da fatia 1 (posse validada na escrita, fatia 3)
                if ($defaultFlow !== null && $defaultFlow->enabled && $guard->contactGatePasses($account->id, $jid)) {
                    // MESMO padrao do passo (2)/fluxo-de-entrada: start + dispatchFlowReply
                    // (o envio passa pelo Sender/freios/delay como qualquer fluxo).
                    $this->dispatchFlowReply($account, $channel, $message, $flows->start($account->id, $defaultFlow, $jid), $guard);

                    return;
                }
            }

            // (4) Camada 3 — FALLBACK: nada casou. Se a IA esta elegivel pro contato
            // (kill switch da IA ON + IA ligada no contato + portao passa), classifica
            // em job SEPARADO (a API tem latencia/429; nao trava o pipeline). Tudo OFF
            // por padrao -> este ramo nao dispara ate o Fabio ligar a IA.
            if ($guard->aiEligible($account->id, $jid)) {
                // MT-0: account_id serializado — o job restaura o contexto no handle.
                ClassifyWithAi::dispatch($message->id, $account->id);
            } elseif ($guard->contactGatePasses($account->id, $jid)) {
                // MATCH-1: silencio ELEGIVEL vira registro (grupo/fromMe ja sairam
                // antes; contato nao aprovado/opt-out NAO entra — ali nao ha
                // oportunidade de regra). A IA, quando elegivel, decide no job.
                \App\Models\UnmatchedMessage::record($account->id, $jid, $data->text);
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
