<?php

namespace App\Whatsapp\Proactive;

use App\Models\AutoReplySetting;
use App\Models\Contact;
use App\Models\FlowSession;
use App\Whatsapp\AutoReply\GuardDecision;
use App\Whatsapp\Secrets\SecretVault;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Proativas P-1 — A JAULA. Freios PROPRIOS e mais duros que os reativos, avaliados
 * ANTES de qualquer disparo (o disparo em si so existe na P-3, via Sender em modo
 * proactive; nesta fatia NADA envia).
 *
 * API por PARAMETRO explicito (como o AntiBanGuard): nao depende do contexto
 * global — queries internas com bypass nomeado + WHERE por conta.
 *
 * ORDEM DOS BLOQUEIOS (cada um com motivo nomeado; barato primeiro, nada de
 * gastar contador antes do switch):
 *  a) proactive_off        — kill switch PROPRIO (independente do robo) OFF
 *  b) grupo                — proativa e SO pra pessoa (grupo jamais)
 *  c) opt_out              — contato com auto_reply_mode=off JAMAIS recebe proativa
 *  d) sem_opt_in           — sem consentimento explicito (proactive_opt_in)
 *  e) fluxo_ativo          — sessao de fluxo em andamento (nao atropela conversa)
 *  f) fora_da_janela_proativa — janela PROPRIA (D5: 09-18h, fuso Sao Paulo)
 *  g) teto_dia_proativo    — teto diario da CONTA (D5: 20/dia)
 *  h) teto_semana_contato  — limite por CONTATO/semana (D5: 1/semana)
 *  i) contem_senha         — {senha:} e PROIBIDO em proativa, SEM excecao
 *
 * Contadores em cache (Redis em producao), por conta e por conta+contato, com
 * dia/semana no fuso de exibicao (SP). check (leitura) NAO consome; claim()
 * consome ATOMICO com rollback se estourou — o disparo real (P-3) faz
 * allows() -> claim() -> envia.
 */
class ProactiveGuard
{
    public function __construct(private SecretVault $vault)
    {
    }

    public function allows(int $accountId, int $contactId, string $content, ?Carbon $now = null): GuardDecision
    {
        $now = $now ?: Carbon::now();
        $settings = $this->settingsFor($accountId);

        // a) kill switch proprio (nasce OFF; independente do kill switch reativo).
        if (! $settings->proactive_enabled) {
            return GuardDecision::block('proactive_off');
        }

        $contact = Contact::withoutAccountScope()
            ->where('account_id', $accountId)->find($contactId);
        if ($contact === null) {
            return GuardDecision::block('contato_inexistente');
        }

        // b) grupo jamais.
        if (str_ends_with((string) $contact->remote_jid, '@g.us')) {
            return GuardDecision::block('grupo');
        }

        // c) quem optou out do ROBO tambem nunca recebe proativa.
        if ($contact->auto_reply_mode === 'off') {
            return GuardDecision::block('opt_out');
        }

        // d) opt-in EXPLICITO obrigatorio (consentimento auditado em proactive_consents).
        if (! $contact->proactive_opt_in) {
            return GuardDecision::block('sem_opt_in');
        }

        // e) conversa em andamento (sessao de fluxo ativa e nao expirada).
        $fluxoAtivo = FlowSession::withoutAccountScope()
            ->where('account_id', $accountId)
            ->where('remote_jid', $contact->remote_jid)
            ->where('status', 'active')
            ->where('expires_at', '>', $now)
            ->exists();
        if ($fluxoAtivo) {
            return GuardDecision::block('fluxo_ativo');
        }

        // f) janela PROPRIA das proativas (fuso SP, como o resto do app).
        if (! $this->withinWindow($settings, $now)) {
            return GuardDecision::block('fora_da_janela_proativa');
        }

        // g) teto diario da conta (check — NAO consome; o claim e do disparo).
        if ($this->dayCount($accountId, $now) >= (int) $settings->proactive_daily_cap) {
            return GuardDecision::block('teto_dia_proativo');
        }

        // h) limite por contato/semana.
        if ($this->weekCount($accountId, $contactId, $now) >= (int) $settings->proactive_per_contact_weekly_cap) {
            return GuardDecision::block('teto_semana_contato');
        }

        // i) segredo PROIBIDO em proativa — sem excecao (nem escopo salva aqui).
        if ($this->vault->hasRef($content)) {
            return GuardDecision::block('contem_senha');
        }

        return GuardDecision::allow();
    }

    // ---- contadores: check (leitura) vs claim (consumo atomico) ----------------

    public function dayCount(int $accountId, ?Carbon $now = null): int
    {
        return (int) Cache::get($this->dayKey($accountId, $now), 0);
    }

    public function weekCount(int $accountId, int $contactId, ?Carbon $now = null): int
    {
        return (int) Cache::get($this->weekKey($accountId, $contactId, $now), 0);
    }

    /**
     * CONSOME uma vaga nos dois contadores, ATOMICO com rollback: se qualquer teto
     * estourar no incremento, devolve a vaga e retorna false (nada e gasto).
     * Usado pelo disparo real (P-3) DEPOIS do allows(); aqui, testado por unidade.
     */
    public function claim(int $accountId, int $contactId, ?Carbon $now = null): bool
    {
        $settings = $this->settingsFor($accountId);
        $now = $now ?: Carbon::now();

        $dayKey = $this->dayKey($accountId, $now);
        Cache::add($dayKey, 0, $this->secondsToEndOfDay($now));
        $dia = (int) Cache::increment($dayKey);
        if ($dia > (int) $settings->proactive_daily_cap) {
            Cache::decrement($dayKey);

            return false;
        }

        $weekKey = $this->weekKey($accountId, $contactId, $now);
        Cache::add($weekKey, 0, 8 * 86400);
        $semana = (int) Cache::increment($weekKey);
        if ($semana > (int) $settings->proactive_per_contact_weekly_cap) {
            Cache::decrement($weekKey);
            Cache::decrement($dayKey); // rollback completo: nada e gasto

            return false;
        }

        return true;
    }

    // ---- internos ----------------------------------------------------------------

    /** API por parametro (bypass nomeado, como o AntiBanGuard::settingsFor). */
    private function settingsFor(int $accountId): AutoReplySetting
    {
        return AutoReplySetting::withoutAccountScope()->firstOrCreate(['account_id' => $accountId]);
    }

    private function withinWindow(AutoReplySetting $settings, Carbon $now): bool
    {
        $local = $now->copy()->setTimezone((string) config('app.display_timezone'))->format('H:i:s');
        $start = substr((string) $settings->proactive_window_start, 0, 8);
        $end = substr((string) $settings->proactive_window_end, 0, 8);

        return $local >= $start && $local <= $end;
    }

    private function dayKey(int $accountId, ?Carbon $now = null): string
    {
        $dia = ($now ?: Carbon::now())->copy()->setTimezone((string) config('app.display_timezone'))->format('Ymd');

        return "proactive:{$accountId}:day:{$dia}";
    }

    private function weekKey(int $accountId, int $contactId, ?Carbon $now = null): string
    {
        // Semana ISO no fuso SP (oW = ano ISO + numero da semana).
        $semana = ($now ?: Carbon::now())->copy()->setTimezone((string) config('app.display_timezone'))->format('oW');

        return "proactive:{$accountId}:contact:{$contactId}:week:{$semana}";
    }

    private function secondsToEndOfDay(Carbon $now): int
    {
        $local = $now->copy()->setTimezone((string) config('app.display_timezone'));

        return max(60, $local->copy()->endOfDay()->getTimestamp() - $local->getTimestamp() + 5);
    }
}
