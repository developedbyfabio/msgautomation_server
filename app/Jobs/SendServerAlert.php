<?php

namespace App\Jobs;

use App\Channels\ProviderRegistry;
use App\Mail\ServersAlertFallback;
use App\Models\Channel;
use App\Models\SystemEvent;
use App\Servers\AlertContact;
use App\Servers\AlertMessageResolver;
use App\Servers\AlertRule;
use App\Servers\Incident;
use App\Servers\ServerAlertSetting;
use App\Tenancy\AccountContext;
use App\Whatsapp\Exceptions\WhatsappSendException;
use App\Whatsapp\SystemConversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * Servidores S3 — envio dos alertas de UMA conta, SEMPRE em fila, atras do flag
 * servers.notifications_enabled (OFF = job nao envia nada; o modo silencioso da
 * S2 segue no AlertNotifier). Despachado pelo servers:evaluate ao fim do tick
 * (um por conta com pendencia) — o envio NUNCA ocorre no request nem dentro da
 * avaliacao.
 *
 * Coalesce por conta (B3 tempestade): reune TODOS os incidentes pendentes de
 * notificacao no momento em que roda e monta UMA mensagem por destinatario
 * (rack caindo = "N servidores sem resposta", nao N mensagens). Acima de
 * storm_cap, resumo. burst_cap por janela corta rajada por tenant.
 *
 * Tres baldes de pendencia (idempotentes via marcas persistidas):
 *  - ABERTURA/ESCALADA: incidente aberto com notified_level != level.
 *  - RESOLUCAO: resolvido com notified_resolved_at NULL.
 *  - RE-NOTIFICACAO: so critical NAO-reconhecido (status firing), ja notificado,
 *    com last_notified_at + cooldown vencido (warning nunca repete; ack silencia).
 *
 * Transporte DIRETO (decisao travada): ProviderRegistry->for($channel)->sendText
 * — o mesmo transporte cru das Campanhas, SEM os freios de marketing (que
 * poderiam segurar um alerta). Falha de entrega e OBSERVAVEL (B4): retry com
 * backoff; esgotado -> SystemEvent 'error' + fallback e-mail.
 */
class SendServerAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [30, 60, 120, 300];

    public function __construct(public readonly int $accountId) {}

    /** Ha algo a notificar nesta conta agora? (evita despachar job no-op a cada tick). */
    public static function hasPending(int $accountId): bool
    {
        return Incident::withoutAccountScope()->where('account_id', $accountId)
            ->where(function ($q) {
                $q->where(fn ($q) => $q->whereNull('resolved_at') // abertura/escalada
                    ->where(fn ($q) => $q->whereNull('notified_level')->orWhereColumn('notified_level', '!=', 'level')))
                    ->orWhere(fn ($q) => $q->whereNotNull('resolved_at')->whereNull('notified_resolved_at')) // resolucao
                    // candidato a re-aviso: incidente ABERTO ja notificado CUJA regra tem
                    // cadencia (>0) para o nivel. Sem ack: o unico gate e "aberto + cadencia".
                    // Evita job no-op perpetuo em regras "avisar 1 vez" (repeat NULL). O
                    // intervalo exato e checado no pendingReminders.
                    ->orWhere(fn ($q) => $q->whereNull('resolved_at')
                        ->whereColumn('notified_level', 'level')
                        ->whereExists(fn ($sub) => $sub->from('server_alert_rules')
                            ->whereColumn('server_alert_rules.id', 'server_incidents.rule_id')
                            ->where(fn ($w) => $w
                                ->where(fn ($a) => $a->where('server_incidents.level', 'warning')->where('warning_repeat_s', '>', 0))
                                ->orWhere(fn ($a) => $a->where('server_incidents.level', 'critical')->where('critical_repeat_s', '>', 0)))));
            })->exists();
    }

    public function handle(ProviderRegistry $registry): void
    {
        if (! config('servers.notifications_enabled')) {
            return; // flag OFF: silencioso fica com o AlertNotifier (S2)
        }

        app(AccountContext::class)->runAs($this->accountId, function () use ($registry) {
            $channel = Channel::defaultFor($this->accountId);
            $contacts = AlertContact::withoutAccountScope()
                ->where('account_id', $this->accountId)->where('enabled', true)->get();

            $opens = $this->pendingOpens();
            $resolved = $this->pendingResolved();
            $reminders = $this->pendingReminders();

            if ($opens->isEmpty() && $resolved->isEmpty() && $reminders->isEmpty()) {
                return;
            }

            // Conversa de sistema (Atendimento): re-avisos aparecem com o MESMO
            // texto rotacionado. Abertura/escalada/resolucao ja foram gravadas
            // pelo AlertNotifier na transicao (nao re-gravar aqui).
            $this->registrarReavisosNaConversa($reminders);

            // Envia por destinatario (roteamento por severidade+escopo) e balde.
            // JANELA DE HORARIO (Feature 1): fora da janela/fim de semana, o
            // WhatsApp DESTE contato e suprimido — sem acumulo, ele nao recebe
            // "depois". O incidente e a conversa "Alertas de Infraestrutura" ja
            // foram gravados pelo AlertNotifier (upstream): o fato nao se perde,
            // so a entrega a este contato fora de hora e descartada.
            foreach ($contacts as $contact) {
                if (! $contact->withinWindow()) {
                    continue;
                }
                $this->sendTo($registry, $channel, $contact, 'abertura', $opens);
                $this->sendTo($registry, $channel, $contact, 'reincidencia', $reminders);
                $this->sendTo($registry, $channel, $contact, 'resolucao', $resolved);
            }

            // Marcacao SO apos o envio da rodada inteira ter dado certo (excecao
            // de transporte relanca o job antes daqui — retry re-envia).
            $this->marcarNotificados($opens, $resolved, $reminders);
        });
    }

    /** @return Collection<int,Incident> abertos com notificacao pendente. */
    private function pendingOpens()
    {
        return Incident::withoutAccountScope()->where('account_id', $this->accountId)
            ->whereNull('resolved_at')
            ->where(fn ($q) => $q->whereNull('notified_level')->orWhereColumn('notified_level', '!=', 'level'))
            ->with('server')->get();
    }

    private function pendingResolved()
    {
        return Incident::withoutAccountScope()->where('account_id', $this->accountId)
            ->whereNotNull('resolved_at')->whereNull('notified_resolved_at')
            ->with('server')->get();
    }

    /**
     * Re-aviso conforme a CADENCIA da regra por nivel (warning ou critical):
     * incidente ABERTO (resolved_at NULL) ja notificado, cuja regra define
     * re-aviso (repeat_s > 0) e cujo intervalo venceu. NULL/0 no nivel =
     * "avisar 1 vez" -> nunca entra aqui. Sem ack: o unico gate e aberto +
     * cadencia (re-avisa sempre enquanto o problema persiste).
     */
    private function pendingReminders()
    {
        return Incident::withoutAccountScope()->where('account_id', $this->accountId)
            ->whereNull('resolved_at')
            ->whereColumn('notified_level', 'level')
            ->whereNotNull('last_notified_at')
            ->with(['server'])->get()
            ->filter(function (Incident $i) {
                $rule = $i->rule_id ? AlertRule::withoutAccountScope()->find($i->rule_id) : null;
                $repeat = $rule?->repeatSecondsFor((string) $i->level);

                return $repeat !== null && $i->last_notified_at->addSeconds($repeat)->isPast();
            });
    }

    /** Monta e envia UMA mensagem do balde para o contato (se algo casa a rota dele). */
    private function sendTo(ProviderRegistry $registry, ?Channel $channel, AlertContact $contact, string $balde, $incidents): void
    {
        $meus = $incidents->filter(fn (Incident $i) => $i->server && $contact->matches($i->level, $i->server));
        if ($meus->isEmpty()) {
            return;
        }

        $texto = $this->montarMensagem($balde, $meus);

        // Cap de rajada por conta/janela: estourou -> nao envia mais WhatsApp,
        // registra a supressao (o resumo ja saiu ate aqui). Protege o canal.
        if ($this->rajadaEstourada()) {
            SystemEvent::withoutAccountScope()->firstOrCreate(
                ['ref' => 'srv-alert-burst:'.$this->accountId.':'.now()->format('YmdHi')],
                ['account_id' => $this->accountId, 'type' => 'servidores', 'level' => 'warning',
                    'title' => 'Alertas: rajada suprimida (cap por janela atingido)', 'occurred_at' => now()],
            );

            return;
        }

        if ($channel === null) {
            // Sem canal conectado: falha observavel (B4). Relanca para retry;
            // esgotado, failed() faz fallback e-mail + SystemEvent.
            throw new WhatsappSendException('Sem canal WhatsApp conectado para a conta '.$this->accountId);
        }

        $registry->for($channel)->sendText($channel, $contact->phone, $texto);
        $this->contarRajada();
    }

    /**
     * Texto AGRUPADO por contato: cada incidente vira a SUA mensagem configurada
     * (rotacao por notify_count + variaveis, via AlertMessageResolver); acima de
     * storm_cap vira resumo (B3). Mantem o agrupamento anti-tempestade — o texto
     * de cada linha e o mesmo que vai pra conversa de sistema.
     */
    private function montarMensagem(string $balde, $incidents): string
    {
        $cap = (int) config('servers.storm_cap', 10);

        if ($incidents->count() > $cap) {
            $criticos = $incidents->where('level', 'critical')->count();
            $cabecalho = $balde === 'resolucao' ? '✅ Incidentes resolvidos' : '🔴 Alerta de infraestrutura';

            return $cabecalho." — {$incidents->count()} servidores afetados"
                .($criticos ? " ({$criticos} críticos)" : '')
                .".\nVeja a lista completa em Servidores › Incidentes.";
        }

        $resolver = app(AlertMessageResolver::class);

        // Separador EDITAVEL entre avisos agrupados (linha em branco, tracos, etc.);
        // com um unico aviso, o separador nao aparece (implode so junta 2+).
        $separador = ServerAlertSetting::separatorFor($this->accountId);

        return $incidents->map(fn (Incident $i) => $balde === 'resolucao'
            ? $resolver->resolved($i)
            : $resolver->firing($i)   // usa o notify_count atual do incidente (rotacao)
        )->implode($separador);
    }

    private function marcarNotificados($opens, $resolved, $reminders): void
    {
        // Abertura/escalada: marca o nivel notificado e AVANCA a rotacao.
        foreach ($opens as $i) {
            $i->forceFill([
                'notified_level' => $i->level,
                'notified_firing_at' => $i->notified_firing_at ?? now(),
                'last_notified_at' => now(),
                'notify_count' => (int) $i->notify_count + 1,
            ])->save();
        }
        // Re-aviso: avanca a rotacao (proxima mensagem da lista).
        foreach ($reminders as $i) {
            $i->forceFill([
                'last_notified_at' => now(),
                'notify_count' => (int) $i->notify_count + 1,
            ])->save();
        }
        foreach ($resolved as $i) {
            $i->forceFill(['notified_resolved_at' => now(), 'last_notified_at' => now()])->save();
        }
    }

    /** Grava os re-avisos na conversa de sistema (texto rotacionado atual). */
    private function registrarReavisosNaConversa($reminders): void
    {
        $conv = app(SystemConversation::class);
        $resolver = app(AlertMessageResolver::class);
        foreach ($reminders as $i) {
            try {
                $conv->record($i->account_id, $resolver->firing($i), 'srv-alert:'.$i->id.':reaviso:'.$i->notify_count);
            } catch (\Throwable) {
                // best-effort: a conversa e so exibicao
            }
        }
    }

    private function rajadaEstourada(): bool
    {
        $janela = (int) config('servers.burst_window_s', 300);
        $cap = (int) config('servers.burst_cap', 20);

        return (int) Cache::get($this->burstKey($janela), 0) >= $cap;
    }

    private function contarRajada(): void
    {
        $janela = (int) config('servers.burst_window_s', 300);
        $chave = $this->burstKey($janela);
        Cache::add($chave, 0, $janela);
        Cache::increment($chave);
    }

    private function burstKey(int $janela): string
    {
        return 'srv-alert-burst:'.$this->accountId.':'.intdiv(now()->getTimestamp(), max(1, $janela));
    }

    /**
     * B4 — retries esgotados: alerta NAO pode sumir calado. Registra a falha
     * (observavel nos Logs) e dispara fallback por e-mail (contatos com e-mail
     * + fallback_email global).
     */
    public function failed(\Throwable $e): void
    {
        try {
            SystemEvent::withoutAccountScope()->create([
                'account_id' => $this->accountId,
                'type' => 'servidores',
                'level' => 'error',
                'title' => 'Falha ao notificar alerta por WhatsApp: '.mb_substr($e->getMessage(), 0, 150),
                'detail' => ['account_id' => $this->accountId, 'motivo' => $e->getMessage()],
                'occurred_at' => now(),
            ]);
        } catch (\Throwable) {
            // best-effort
        }

        $this->fallbackEmail($e);
    }

    private function fallbackEmail(\Throwable $e): void
    {
        $destinos = AlertContact::withoutAccountScope()
            ->where('account_id', $this->accountId)->where('enabled', true)
            ->whereNotNull('email')->pluck('email')->all();

        if ($global = config('servers.fallback_email')) {
            $destinos[] = $global;
        }
        $destinos = array_values(array_unique(array_filter($destinos)));
        if ($destinos === []) {
            return; // sem e-mail configurado: a falha ja ficou no SystemEvent
        }

        try {
            Mail::to($destinos)->send(new ServersAlertFallback(mb_substr($e->getMessage(), 0, 300)));
        } catch (\Throwable) {
            // best-effort: o SystemEvent ja garante observabilidade
        }
    }
}
