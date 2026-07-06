<?php

namespace App\Whatsapp\AutoReply;

use App\Models\AutoReplyLog;
use App\Models\AutoReplyRule;
use App\Models\AutoReplySetting;
use App\Models\Contact;

/**
 * Freios anti-ban. Tres caminhos (R1):
 *  - 'auto'      (auto-resposta, Fatia 3): fromMe, grupos, opt-out, kill switch,
 *                 janela, rate por contato + tetos protetivos.
 *  - 'manual'    (envio humano/prova): SO tetos protetivos. NAO passa pelo kill
 *                 switch, janela ou opt-out (intervencao manual e override).
 *  - 'aprovacao' (Camada 3 Fatia 3 — envio APROVADO por humano no /revisao): mesma
 *                 politica do manual quanto a kill switch/janela/allowlist (decisao
 *                 humana e override), MAS a guarda protetiva de OPT-OUT vale: contato
 *                 'off' nunca recebe, nem aprovado. + tetos protetivos.
 *
 * Idempotencia da auto-resposta NAO vive aqui: e garantida pelo claim com indice
 * unico em auto_reply_logs.incoming_message_id (ver Sender).
 */
class AntiBanGuard
{
    public function __construct(private Throttle $throttle)
    {
    }

    public function check(string $mode, int $accountId, string $jid, bool $fromMe = false, ?int $ruleId = null, bool $flow = false, bool $handoff = false): GuardDecision
    {
        $settings = $this->settingsFor($accountId);

        if ($mode === 'auto') {
            if ($fromMe) {
                return GuardDecision::block('from_me');
            }
            if ($settings->skip_groups && $this->isGroup($jid)) {
                return GuardDecision::block('grupo');
            }
            // Fatia 5: a DESPEDIDA de handoff pula SO o gate de contato — o 'off' foi
            // setado pelo proprio handoff e nao pode bloquear a propria mensagem.
            // fromMe/grupo (acima) e kill switch/janela/tetos (abaixo) continuam valendo.
            if (! $handoff) {
                $gate = $this->contactGate($accountId, $jid, $settings);
                if (! $gate->allowed) {
                    return $gate;
                }
            }
            if (! $settings->enabled) {
                return GuardDecision::block('kill_switch');
            }
            if (! $this->withinWindow($settings)) {
                return GuardDecision::block('fora_da_janela');
            }
            // S2: cooldown por regra SUBSTITUI o rate-por-contato global (quando a regra
            // define um modo proprio); senao cai no rate global. Fatia A: respostas de
            // FLUXO (sessao ativa) sao ISENTAS do intervalo-por-contato — senao o
            // vai-e-volta do menu travaria. Os tetos de volume (checkCaps) seguem valendo.
            if (! $flow) {
                $cd = $this->rateOrCooldown($accountId, $jid, $ruleId, $settings);
                if (! $cd->allowed) {
                    return $cd;
                }
            }
        }

        // Envio aprovado (Fatia 3): kill switch/janela/allowlist NAO se aplicam
        // (decisao humana, como o manual) — mas opt-out e guarda protetiva dura.
        if ($mode === 'aprovacao' && $this->contactMode($accountId, $jid) === 'off') {
            return GuardDecision::block('opt_out');
        }

        // Tetos protetivos: valem para TODOS os caminhos.
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
            // S2: intervalo por contato desligado (toggle) -> nao bloqueia.
            $segundos = (int) $settings->contact_rate_seconds;
            if (! $settings->contact_rate_enabled || $segundos <= 0) {
                return GuardDecision::allow();
            }

            $ultimaContato = AutoReplyLog::withoutAccountScope()
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

        $ultima = AutoReplyLog::withoutAccountScope()
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

    /**
     * Fatia 5 — R2 da DESPEDIDA de handoff: mesmo recheck volatil, MENOS o gate de
     * contato (o 'off' acabou de ser setado pelo proprio handoff — nao pode barrar
     * a propria mensagem). Kill switch e janela continuam valendo; tetos ja foram
     * checados no check().
     */
    public function volatileRecheckHandoff(int $accountId): GuardDecision
    {
        $settings = $this->settingsFor($accountId);

        if (! $settings->enabled) {
            return GuardDecision::block('kill_switch');
        }
        if (! $this->withinWindow($settings)) {
            return GuardDecision::block('fora_da_janela');
        }

        return GuardDecision::allow();
    }

    /**
     * S3 — quadro COMPLETO dos freios (transparencia), somente-leitura. Lista cada
     * freio na ordem de avaliacao com status: passa | bloqueia | desligado | na
     * (nao se aplica). Respeita os toggles do S2. Nao muta nada.
     *
     * @return array<int,array{label:string,status:string,detalhe:?string}>
     */
    public function breakdown(int $accountId, string $jid, ?int $ruleId = null): array
    {
        $settings = $this->settingsFor($accountId);
        $rule = $ruleId !== null ? AutoReplyRule::find($ruleId) : null;
        $mode = $rule?->cooldown_mode ?: 'global';
        $contactMode = $this->contactMode($accountId, $jid);
        $itens = [];

        $add = function (string $label, string $status, ?string $detalhe = null) use (&$itens) {
            $itens[] = ['label' => $label, 'status' => $status, 'detalhe' => $detalhe];
        };

        // Guardas estruturais (sempre ativos). No dry-run, uma mensagem recebida nao e
        // fromMe e nao e duplicata -> passam; mostramos pra transparencia.
        $add('fromMe (proprio numero)', 'passa', 'sempre ativo');
        $add('Idempotencia (duplicata)', 'passa', 'sempre ativo');

        // Grupo.
        if ($settings->skip_groups && $this->isGroup($jid)) {
            $add('Pular grupos', 'bloqueia', 'e um grupo');
        } else {
            $add('Pular grupos', $settings->skip_groups ? 'passa' : 'desligado', null);
        }

        // Aprovacao do contato.
        $add('Aprovacao do contato', $contactMode === 'off' ? 'bloqueia' : 'passa',
            $contactMode === 'off' ? 'contato silenciado (off)' : "modo: {$contactMode}");

        // Politica de resposta.
        $policy = $settings->reply_policy ?: 'allowlist';
        $polBloqueia = $policy === 'allowlist' && $contactMode !== 'on';
        $add('Politica de resposta', $polBloqueia ? 'bloqueia' : 'passa',
            $policy === 'allowlist' ? 'allowlist: so contatos on' : 'todos (menos off)');

        // Kill switch.
        $add('Autoresponder (kill switch)', $settings->enabled ? 'passa' : 'bloqueia',
            $settings->enabled ? 'ligado' : 'robo desligado');

        // Janela.
        if (! $settings->window_enabled) {
            $add('Janela de horario', 'desligado', null);
        } else {
            $add('Janela de horario', $this->withinWindow($settings) ? 'passa' : 'bloqueia', null);
        }

        // S3 — deixar claro QUEM governa a frequencia: o intervalo global OU o cooldown
        // proprio da regra (um substitui o outro).
        $freqRegra = $this->cooldownLabel($mode, $rule);
        if ($mode !== 'global') {
            // A regra define a propria frequencia -> o global nao se aplica.
            $add('Intervalo por contato (global)', 'na', 'nao se aplica — esta regra define a propria frequencia');
            $dec = $this->rateOrCooldown($accountId, $jid, $ruleId, $settings);
            $bloqueia = in_array($dec->reason, ['cooldown', 'cooldown_dia'], true);
            $add('Frequencia desta regra', $bloqueia ? 'bloqueia' : 'passa', $freqRegra);
        } else {
            // A regra usa o global -> o global governa.
            if (! $settings->contact_rate_enabled || (int) $settings->contact_rate_seconds <= 0) {
                $add('Intervalo por contato (global)', 'desligado', 'esta regra usa o global');
            } else {
                $bloqueia = $this->rateOrCooldown($accountId, $jid, $ruleId, $settings)->reason === 'rate_contato';
                $add('Intervalo por contato (global)', $bloqueia ? 'bloqueia' : 'passa', $settings->contact_rate_seconds . 's (governa esta regra)');
            }
            $add('Frequencia desta regra', 'na', 'usa o intervalo por contato (global)');
        }

        // Tetos de volume.
        if (! $settings->min_interval_enabled) {
            $add('Intervalo minimo', 'desligado', null);
        } else {
            $since = $this->throttle->secondsSinceLastSend($accountId);
            $add('Intervalo minimo', ($since !== null && $since < $settings->min_interval_seconds) ? 'bloqueia' : 'passa', $settings->min_interval_seconds . 's');
        }
        if (! $settings->per_minute_enabled) {
            $add('Teto / minuto', 'desligado', null);
        } else {
            $add('Teto / minuto', $this->throttle->minuteHits($accountId) >= $settings->per_minute_cap ? 'bloqueia' : 'passa', null);
        }
        if (! $settings->per_day_enabled) {
            $add('Teto / dia', 'desligado', null);
        } else {
            $add('Teto / dia', $this->throttle->dayHits($accountId) >= $settings->per_day_cap ? 'bloqueia' : 'passa', null);
        }

        return $itens;
    }

    /** S3 — descricao amigavel da frequencia propria da regra (pro testador). */
    private function cooldownLabel(string $mode, ?AutoReplyRule $rule): string
    {
        return match ($mode) {
            'sempre' => 'responde sempre (sem limite por contato)',
            '1x_dia' => '1x por dia por contato',
            'cada_n' => 'a cada ' . max(1, (int) ($rule->cooldown_minutes ?? 0)) . ' min por contato',
            default => 'intervalo por contato (global)',
        };
    }

    public function isGroup(string $jid): bool
    {
        return str_ends_with($jid, '@g.us');
    }

    // ---- Camada 3 (IA): elegibilidade e limiar (somente leitura) -------------

    /**
     * A IA pode SEQUER classificar este contato? Pre-checagem barata ANTES de gastar
     * chamada de API: kill switch da IA (GLOBAL, por conta) + nao-grupo + portao de
     * contato (allowlist/on/off — o mute continua vetando). Nao substitui os freios
     * de ENVIO — a resposta ainda passa pelo Sender (todos os freios + R2).
     *
     * Fatia 16 — CONSOLIDACAO: o flag por contato (Contact.ai_enabled) saiu da
     * composicao (pedido do dono: IA liga/desliga NO GERAL). A coluna fica
     * DORMENTE (padrao warmup_enabled) — nao removida, nao mais lida/escrita.
     * Impacto medido em producao antes da mudanca: 0 contatos com o flag ligado.
     */
    public function aiEligible(int $accountId, string $jid): bool
    {
        $settings = $this->settingsFor($accountId);

        if (! $settings->ai_enabled) {
            return false;
        }
        if ($this->isGroup($jid)) {
            return false;
        }

        return $this->contactGate($accountId, $jid, $settings)->allowed;
    }

    public function aiConfidenceThreshold(int $accountId): float
    {
        return (float) $this->settingsFor($accountId)->ai_confidence_threshold;
    }

    public function settingsFor(int $accountId): AutoReplySetting
    {
        // MT-0: o guard e uma API POR PARAMETRO (o chamador diz a conta) — bypass
        // NOMEADO + WHERE explicito, pra nunca depender do contexto global (que
        // poderia divergir em chamada cross-account legitima, ex.: gate de teste).
        return AutoReplySetting::withoutAccountScope()->firstOrCreate(['account_id' => $accountId]);
    }

    private function checkCaps(int $accountId, AutoReplySetting $settings): GuardDecision
    {
        // S2: cada teto so vale se o respectivo toggle estiver ligado.
        if ($settings->min_interval_enabled) {
            $since = $this->throttle->secondsSinceLastSend($accountId);
            if ($since !== null && $since < $settings->min_interval_seconds) {
                return GuardDecision::block('intervalo_minimo');
            }
        }
        if ($settings->per_minute_enabled && $this->throttle->minuteHits($accountId) >= $settings->per_minute_cap) {
            return GuardDecision::block('teto_minuto');
        }
        if ($settings->per_day_enabled && $this->throttle->dayHits($accountId) >= $settings->per_day_cap) {
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

        // Fatia 4 — politica EFETIVA: modo AUTOMATICO atende desconhecidos ('all')
        // em TODOS os caminhos que passam por este gate (regra, fluxo-de-entrada,
        // catch-all e o re-check R2 do Sender via volatileRecheck). Mute ('off',
        // acima) e tetos/throttle (fora deste gate) ficam intactos. Em personal,
        // comportamento IDENTICO ao atual.
        $policy = $settings->operation_mode === \App\Enums\OperationMode::Auto
            ? 'all'
            : ($settings->reply_policy ?: 'allowlist');
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
        // MT-0: API por parametro — bypass nomeado + WHERE explicito (ver settingsFor).
        return (string) (Contact::withoutAccountScope()
            ->where('account_id', $accountId)
            ->where('remote_jid', $jid)
            ->value('auto_reply_mode') ?? 'default');
    }

    private function withinWindow(AutoReplySetting $settings): bool
    {
        // S2: janela desligada (toggle) -> nao bloqueia (sempre "dentro").
        if (! $settings->window_enabled) {
            return true;
        }

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
