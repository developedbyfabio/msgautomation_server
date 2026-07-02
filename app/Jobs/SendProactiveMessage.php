<?php

namespace App\Jobs;

use App\Models\AutoReplySetting;
use App\Models\CampaignTarget;
use App\Models\ProactiveCampaign;
use App\Tenancy\AccountContext;
use App\Whatsapp\AutoReply\RuleResponder;
use App\Whatsapp\AutoReply\Sender;
use App\Whatsapp\Proactive\AgendaBuilder;
use App\Whatsapp\Proactive\ProactiveGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Proativas P-3 — envio de UM target de campanha. O codigo mais perigoso do
 * sistema, inteiro DENTRO da jaula da P-1:
 *
 *   claim do TARGET (pending -> processing atomico; corrida perde e sai)
 *   -> guard.allows (TODOS os 9 freios) -> claim ATOMICO dos tetos
 *   -> render local dos placeholders -> Sender modo 'proactive' (R2 volatil no
 *   instante do POST; bloqueou = job DEVOLVE o claim).
 *
 * Resultados: sent (target + log origem proactive) · bloqueio tratado por motivo
 * (teto diario -> reagenda amanha; janela -> reagenda abertura; semanal ->
 * reagenda +7d; fluxo ativo -> +60min; switch OFF -> pending aguardando SEM novo
 * horario; opt-out/off/sem opt-in/senha -> skipped definitivo) · erro transitorio
 * de HTTP -> devolve claim + target volta a pending + retry limitado da fila
 * (esgotou -> failed). Idempotencia dura: SO envia quem claimou o target.
 */
class SendProactiveMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;          // 1 tentativa + 2 retries
    public array $backoff = [30, 60];

    public function __construct(
        public readonly int $targetId,
        public readonly int $accountId,
    ) {
    }

    public function handle(ProactiveGuard $guard, Sender $sender, RuleResponder $responder, AgendaBuilder $agenda): void
    {
        app(AccountContext::class)->set($this->accountId);

        $target = CampaignTarget::query()->whereKey($this->targetId)->first();
        if (! $target) {
            return;
        }
        $campaign = ProactiveCampaign::withoutAccountScope()->find($target->campaign_id);
        if (! $campaign || (int) $campaign->account_id !== $this->accountId) {
            return; // defensivo: target fora da conta do job
        }

        // CLAIM DO TARGET (atomico): pending -> processing. Corrida (tick duplo/
        // worker duplo) perde aqui e sai — NUNCA ha dois envios do mesmo target.
        $claimed = CampaignTarget::query()
            ->whereKey($target->id)
            ->where('status', 'pending')
            ->update(['status' => 'processing']);
        if ($claimed === 0) {
            return;
        }
        $target->refresh();

        // Campanha pausada/cancelada no meio (corrida rara com o tick).
        if (! in_array($campaign->status, ['approved', 'running'], true)) {
            $novo = $campaign->status === 'cancelled'
                ? ['status' => 'skipped', 'skip_reason' => 'cancelada']
                : ['status' => 'pending']; // paused: volta e aguarda retomar
            $target->update($novo);

            return;
        }

        if ($campaign->status === 'approved') {
            $campaign->update(['status' => 'running']);
        }

        $contact = $target->contact()->first();
        if (! $contact) {
            $this->finalizar($target, $campaign, ['status' => 'skipped', 'skip_reason' => 'contato_inexistente']);

            return;
        }

        // P-4 — rodape de saida OBRIGATORIO: texto final = mensagem + linha em
        // branco + rodape (template do snapshot; fallback no padrao da conta —
        // a obrigacao vale ate pra campanha antiga sem a coluna preenchida).
        // A jaula e o render avaliam o texto FINAL concatenado.
        $settings = AutoReplySetting::withoutAccountScope()
            ->where('account_id', $this->accountId)->first();
        $rodape = trim((string) ($campaign->optout_footer
            ?: $settings?->proactive_optout_footer
            ?: \App\Whatsapp\Proactive\OptoutFooterGuard::DEFAULT));
        $template = trim((string) $campaign->message) . "\n\n" . $rodape;

        // JAULA COMPLETA (9 freios) antes de gastar qualquer coisa.
        $decision = $guard->allows($this->accountId, (int) $contact->id, $template);
        if (! $decision->allowed) {
            $this->tratarBloqueio($target, $campaign, (string) $decision->reason, $agenda);

            return;
        }

        // CLAIM ATOMICO dos tetos (com rollback interno se estourar na corrida).
        if (! $guard->claim($this->accountId, (int) $contact->id)) {
            $this->tratarBloqueio($target, $campaign, 'teto_dia_proativo', $agenda);

            return;
        }

        // CH-1: escolha EXPLICITA do canal da conta (mesma semantica historica).
        $canal = \App\Models\Channel::defaultFor($this->accountId);
        if (! $canal) {
            $guard->release($this->accountId, (int) $contact->id);
            $this->finalizar($target, $campaign, ['status' => 'failed', 'skip_reason' => 'sem_canal']);

            return;
        }

        // Renderizador UNICO sobre o conjunto: {palavra_sair} resolve pro valor
        // ATUAL das settings (trocar a palavra muda ate campanha ja aprovada).
        $texto = $responder->render($template, [
            'nome' => $contact->push_name,
            'now' => now(),
        ]);

        // Sender modo 'proactive': tetos protetivos globais + R2 volatil no POST.
        $log = $sender->send(
            mode: 'proactive',
            channel: $canal,
            jid: (string) $contact->remote_jid,
            text: $texto,
            campaignId: (int) $campaign->id,
        );

        if ($log->status === 'sent') {
            $this->finalizar($target, $campaign, [
                'status' => 'sent',
                'sent_at' => now(),
                'sent_auto_reply_log_id' => $log->id,
            ]);
            Log::info('Proativa enviada.', ['campaign' => $campaign->id, 'target' => $target->id]);

            return;
        }

        if ($log->status === 'blocked') {
            // R2/tetos seguraram DEPOIS do claim -> devolve a vaga (nada enviado).
            $guard->release($this->accountId, (int) $contact->id);
            $this->tratarBloqueio($target, $campaign, (string) $log->motivo, $agenda);

            return;
        }

        // failed (erro de envio HTTP): devolve claim + target volta a pending e
        // LANCA pro mecanismo padrao de retry da fila (backoff; tries limitado).
        $guard->release($this->accountId, (int) $contact->id);
        $target->update(['status' => 'pending']);
        Log::warning('Proativa: erro de envio (retry da fila).', ['campaign' => $campaign->id, 'target' => $target->id, 'motivo' => $log->motivo]);

        throw new \RuntimeException('Envio proativo falhou (transitorio): ' . ($log->motivo ?: 'erro_envio'));
    }

    /** Esgotou as tentativas: failed definitivo com motivo. */
    public function failed(\Throwable $e): void
    {
        app(AccountContext::class)->set($this->accountId);
        CampaignTarget::query()->whereKey($this->targetId)
            ->whereIn('status', ['pending', 'processing'])
            ->update(['status' => 'failed', 'skip_reason' => 'erro_envio']);
        Log::error('Proativa: target FAILED apos esgotar retries.', ['target' => $this->targetId]);
    }

    /** Trata bloqueio do guard/R2 por motivo (reagenda, aguarda ou pula). */
    private function tratarBloqueio(CampaignTarget $target, ProactiveCampaign $campaign, string $motivo, AgendaBuilder $agenda): void
    {
        $settings = AutoReplySetting::withoutAccountScope()->firstOrCreate(['account_id' => $this->accountId]);
        $tz = (string) config('app.display_timezone');

        switch ($motivo) {
            case 'proactive_off':
                // Interruptor desligado: aguarda religar, SEM novo horario.
                $target->update(['status' => 'pending']);
                Log::info('Proativa: aguardando interruptor (pending).', ['target' => $target->id]);
                break;

            case 'teto_dia_proativo':
                // Teto do dia: a campanha CONTINUA amanha (ninguem se perde).
                $amanha = now()->setTimezone($tz)->addDay()->startOfDay();
                $novo = $agenda->build($settings, $amanha, 1)[0];
                $target->update(['status' => 'pending', 'scheduled_at' => $novo]);
                Log::info('Proativa: teto do dia — reagendada pra amanha.', ['target' => $target->id]);
                break;

            case 'teto_semana_contato':
                // Limite semanal do contato: proxima semana, na janela.
                $novo = $agenda->build($settings, now()->addDays(7), 1)[0];
                $target->update(['status' => 'pending', 'scheduled_at' => $novo]);
                Log::info('Proativa: limite semanal — reagendada +7d.', ['target' => $target->id]);
                break;

            case 'fora_da_janela_proativa':
                // Janela fechada: reagenda pra proxima abertura.
                $novo = $agenda->build($settings, now(), 1)[0];
                $target->update(['status' => 'pending', 'scheduled_at' => $novo]);
                Log::info('Proativa: fora da janela — reagendada pra abertura.', ['target' => $target->id]);
                break;

            case 'fluxo_ativo':
                // Conversa em andamento: tenta de novo em ~1h (dentro da janela).
                $novo = $agenda->build($settings, now()->addHour(), 1)[0];
                $target->update(['status' => 'pending', 'scheduled_at' => $novo]);
                Log::info('Proativa: fluxo ativo — reagendada +1h.', ['target' => $target->id]);
                break;

            default:
                // opt_out | sem_opt_in | grupo | contem_senha | contato_inexistente:
                // definitivo — NUNCA mais tenta este contato nesta campanha.
                $this->finalizar($target, $campaign, ['status' => 'skipped', 'skip_reason' => $motivo]);
                Log::warning('Proativa: target pulado.', ['target' => $target->id, 'motivo' => $motivo]);
        }
    }

    /** Aplica o estado final do target e fecha a campanha se nada restou. */
    private function finalizar(CampaignTarget $target, ProactiveCampaign $campaign, array $dados): void
    {
        $target->update($dados);

        $restam = CampaignTarget::query()
            ->where('campaign_id', $campaign->id)
            ->whereIn('status', ['pending', 'processing'])
            ->exists();

        if (! $restam && in_array($campaign->fresh()->status, ['approved', 'running'], true)) {
            $campaign->update(['status' => 'done']);
            Log::info('Proativa: campanha concluida.', ['campaign' => $campaign->id]);
        }
    }
}
