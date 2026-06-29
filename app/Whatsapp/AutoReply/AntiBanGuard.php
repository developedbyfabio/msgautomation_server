<?php

namespace App\Whatsapp\AutoReply;

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

    public function check(string $mode, int $accountId, string $jid, bool $fromMe = false): GuardDecision
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
            if ($this->throttle->contactRecentlyReplied($accountId, $jid)) {
                return GuardDecision::block('rate_contato');
            }
        }

        // Tetos protetivos: valem para AMBOS os caminhos.
        return $this->checkCaps($accountId, $settings);
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
        $now = now((string) config('app.timezone'))->format('H:i:s');
        $start = substr((string) $settings->window_start, 0, 8);
        $end = substr((string) $settings->window_end, 0, 8);

        // Janela no mesmo dia (08:00-20:00). Janela que cruza a meia-noite nao e suportada.
        return $now >= $start && $now <= $end;
    }
}
