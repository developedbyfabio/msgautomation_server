<?php

namespace App\Whatsapp\AutoReply;

use App\Models\AutoReplyLog;
use App\Models\AutoReplyRule;
use App\Models\AutoReplySetting;
use App\Models\Contact;

/**
 * Freios anti-ban. Dois caminhos (R1):
 *  - 'auto'   (auto-resposta, Fatia 3): fromMe, grupos, opt-out, kill switch, janela,
 *              rate por contato + tetos protetivos.
 *  - 'manual' (envio humano/prova): SO tetos protetivos. NAO passa pelo kill switch,
 *              janela ou opt-out (intervencao manual e override).
 *
 * Idempotencia da auto-resposta NAO vive aqui: e garantida pelo claim com indice
 * unico em auto_reply_logs.incoming_message_id (ver Sender).
 */
class AntiBanGuard
{
    public function __construct(private Throttle $throttle)
    {
    }

    public function check(string $mode, int $accountId, string $jid, bool $fromMe = false, ?int $ruleId = null): GuardDecision
    {
        $settings = $this->settingsFor($accountId);

        if ($mode === 'auto') {
            if ($fromMe) {
                return GuardDecision::block('from_me');
            }
            if ($settings->skip_groups && $this->isGroup($jid)) {
                return GuardDecision::block('grupo');
            }
            $gate = $this->contactGate($accountId, $jid, $settings);
            if (! $gate->allowed) {
                return $gate;
            }
            if (! $settings->enabled) {
                return GuardDecision::block('kill_switch');
            }
            if (! $this->withinWindow($settings)) {
                return GuardDecision::block('fora_da_janela');
            }
            // S2: cooldown por regra SUBSTITUI o rate-por-contato global (quando a regra
            // define um modo proprio); senao cai no rate global. Os tetos de volume
            // (checkCaps) seguem valendo abaixo como piso de protecao do numero.
            $cd = $this->rateOrCooldown($accountId, $jid, $ruleId, $settings);
            if (! $cd->allowed) {
                return $cd;
            }
        }

        // Tetos protetivos: valem para AMBOS os caminhos.
        return $this->checkCaps($accountId, $settings);
    }

    /**
     * S2 — frequencia por regra (cooldown) por contato, OU o rate global se a regra
     * usa 'global'/sem regra. Rastreio em auto_reply_logs (rule_id, remote_jid, sent_at).
     */
    private function rateOrCooldown(int $accountId, string $jid, ?int $ruleId, AutoReplySetting $settings): GuardDecision
    {
        $rule = $ruleId !== null ? AutoReplyRule::find($ruleId) : null;
        $mode = $rule?->cooldown_mode ?: 'global';

        if ($mode === 'global') {
            // S1: le o VALOR ATUAL de contact_rate_seconds e compara com o ULTIMO
            // auto-reply ao contato (qualquer regra). Antes usava cache com TTL congelado
            // no envio -> mudar o valor nao tinha efeito ate o TTL antigo expirar (stale).
            $segundos = (int) $settings->contact_rate_seconds;
            if ($segundos <= 0) {
                return GuardDecision::allow();
            }

            $ultimaContato = AutoReplyLog::query()
                ->where('account_id', $accountId)
                ->where('remote_jid', $jid)
                ->where('status', 'sent')
                ->whereNotNull('sent_at')
                ->latest('sent_at')
                ->value('sent_at');

            if ($ultimaContato === null) {
                return GuardDecision::allow();
            }

            return $ultimaContato->copy()->greaterThan(now()->subSeconds($segundos))
                ? GuardDecision::block('rate_contato')
                : GuardDecision::allow();
        }

        if ($mode === 'sempre') {
            return GuardDecision::allow();
        }

        $ultima = AutoReplyLog::query()
            ->where('account_id', $accountId)
            ->where('rule_id', $ruleId)
            ->where('remote_jid', $jid)
            ->where('status', 'sent')
            ->whereNotNull('sent_at')
            ->latest('sent_at')
            ->value('sent_at');

        if ($ultima === null) {
            return GuardDecision::allow();
        }

        if ($mode === '1x_dia') {
            // Reset a meia-noite America/Sao_Paulo. sent_at e gravado em UTC.
            $inicioDiaSp = now((string) config('app.display_timezone'))->startOfDay()->setTimezone('UTC');

            return $ultima->copy()->utc()->greaterThanOrEqualTo($inicioDiaSp)
                ? GuardDecision::block('cooldown_dia')
                : GuardDecision::allow();
        }

        if ($mode === 'cada_n') {
            $minutos = max(1, (int) ($rule->cooldown_minutes ?? 0));

            return $ultima->copy()->greaterThan(now()->subMinutes($minutos))
                ? GuardDecision::block('cooldown')
                : GuardDecision::allow();
        }

        return GuardDecision::allow();
    }

    /**
     * R2: re-checa SO as condicoes volateis imediatamente antes do POST (auto).
     * O estado pode mudar entre o enfileiramento, o ->delay() e o envio.
     */
    public function volatileRecheck(int $accountId, string $jid): GuardDecision
    {
        $settings = $this->settingsFor($accountId);

        if (! $settings->enabled) {
            return GuardDecision::block('kill_switch');
        }
        $gate = $this->contactGate($accountId, $jid, $settings);
        if (! $gate->allowed) {
            return $gate;
        }
        if (! $this->withinWindow($settings)) {
            return GuardDecision::block('fora_da_janela');
        }

        return GuardDecision::allow();
    }

    public function isGroup(string $jid): bool
    {
        return str_ends_with($jid, '@g.us');
    }

    public function settingsFor(int $accountId): AutoReplySetting
    {
        return AutoReplySetting::firstOrCreate(['account_id' => $accountId]);
    }

    private function checkCaps(int $accountId, AutoReplySetting $settings): GuardDecision
    {
        $since = $this->throttle->secondsSinceLastSend($accountId);
        if ($since !== null && $since < $settings->min_interval_seconds) {
            return GuardDecision::block('intervalo_minimo');
        }
        if ($this->throttle->minuteHits($accountId) >= $settings->per_minute_cap) {
            return GuardDecision::block('teto_minuto');
        }
        if ($this->throttle->dayHits($accountId) >= $settings->per_day_cap) {
            return GuardDecision::block('teto_dia');
        }

        return GuardDecision::allow();
    }

    /**
     * Portao de contato (Fatia 3): combina reply_policy (account) + auto_reply_mode (contato).
     *  - allowlist: responde SO se mode = 'on'. ('default'/'off' -> bloqueia)
     *  - all:       responde todos, EXCETO mode = 'off'.
     */
    private function contactGate(int $accountId, string $jid, AutoReplySetting $settings): GuardDecision
    {
        $mode = $this->contactMode($accountId, $jid);

        if ($mode === 'off') {
            return GuardDecision::block('opt_out');
        }

        $policy = $settings->reply_policy ?: 'allowlist';
        if ($policy === 'allowlist' && $mode !== 'on') {
            return GuardDecision::block('nao_aprovado');
        }

        return GuardDecision::allow();
    }

    public function contactGatePasses(int $accountId, string $jid): bool
    {
        return $this->contactGate($accountId, $jid, $this->settingsFor($accountId))->allowed;
    }

    public function contactMode(int $accountId, string $jid): string
    {
        return (string) (Contact::query()
            ->where('account_id', $accountId)
            ->where('remote_jid', $jid)
            ->value('auto_reply_mode') ?? 'default');
    }

    private function withinWindow(AutoReplySetting $settings): bool
    {
        // C2: a janela e configurada em horario LOCAL (America/Sao_Paulo, UTC-3 fixo) —
        // avaliamos o "agora" nesse fuso, nao em UTC. So o fuso muda; valores e demais
        // freios ficam intactos.
        $now = now((string) config('app.display_timezone'))->format('H:i:s');
        $start = substr((string) $settings->window_start, 0, 8);
        $end = substr((string) $settings->window_end, 0, 8);

        // Janela no mesmo dia (08:00-20:00). Janela que cruza a meia-noite nao e suportada.
        return $now >= $start && $now <= $end;
    }
}
