<?php

namespace App\Servers;

/**
 * Servidores — resolve o TEXTO de um alerta: escolhe a mensagem (rotacao por
 * indice), substitui as variaveis e cai num padrao sensato quando o dono nao
 * cadastrou mensagem propria. Mesmo texto para o WhatsApp e para a conversa de
 * sistema (Atendimento).
 *
 * ROTACAO: no 1o disparo usa a 1a mensagem (indice 0); a cada re-aviso do mesmo
 * incidente avanca (indice = notify_count); ao acabar a lista, REPETE A ULTIMA.
 *
 * VARIAVEIS (documentadas na tela): {servidor} {metrica} {valor} {nivel}
 * {particao}. {particao} vira vazio quando nao e disco.
 */
class AlertMessageResolver
{
    /** Rotulos das variaveis exibidos na UI (valores saem em portugues). */
    public const VARIAVEIS = [
        '{servidor}' => 'nome do servidor',
        '{ip}' => 'IP / host do servidor',
        '{grupo}' => 'grupo do servidor',
        '{metrica}' => 'CPU / memória / swap / disco / carga / sem reportar',
        '{valor}' => 'valor atual (ex.: 92%)',
        '{nivel}' => 'aviso / crítico',
        '{particao}' => 'partição (só disco)',
    ];

    /** Valor pt-BR de {metrica} na mensagem (distinto dos rotulos de regra da tela). */
    public const METRIC_PT = [
        'cpu' => 'CPU',
        'ram' => 'memória',
        'mem' => 'memória',
        'swap' => 'swap',
        'disk' => 'disco',
        'load' => 'carga',
        'watchdog' => 'sem reportar',
    ];

    /** Valor pt-BR de {nivel} na mensagem. */
    public const LEVEL_PT = [
        'warning' => 'aviso',
        'critical' => 'crítico',
    ];

    /** Template padrao EDITAVEL de disparo (com placeholders; a UI mostra e o dono edita). */
    public static function defaultFiringTemplate(string $level, bool $comParticao = false): string
    {
        $emoji = $level === 'critical' ? '🔴' : '🟡';
        $p = $comParticao ? ' ({particao})' : '';

        return "{$emoji} {servidor} ({ip}): {metrica}{$p} {nivel} ({valor})";
    }

    /** Template padrao EDITAVEL de resolucao. */
    public static function defaultResolvedTemplate(bool $comParticao = false): string
    {
        $p = $comParticao ? ' ({particao})' : '';

        return '✅ {servidor} ({ip}): {metrica}'.$p.' normalizado';
    }

    /**
     * Texto do alerta de ABERTURA/RE-AVISO do incidente (nivel atual), no indice
     * de rotacao dado (default: o notify_count do incidente).
     */
    public function firing(Incident $incident, ?int $index = null): string
    {
        $index ??= (int) $incident->notify_count;
        $custom = $this->pick($incident->rule_id, $incident->level, $index);

        return $this->vars($custom ?? $this->defaultFiring($incident), $incident);
    }

    /** Texto de RESOLUCAO (unico; nao rotaciona). */
    public function resolved(Incident $incident): string
    {
        $custom = $this->pick($incident->rule_id, 'resolved', 0);

        return $this->vars($custom ?? $this->defaultResolved($incident), $incident);
    }

    /** Mensagem cadastrada em (regra, nivel) no indice, com "repete a ultima". Null se nao ha lista. */
    private function pick(?int $ruleId, string $level, int $index): ?string
    {
        if ($ruleId === null) {
            return null;
        }
        $lista = AlertMessage::withoutAccountScope()
            ->where('rule_id', $ruleId)->where('level', $level)
            ->orderBy('position')->pluck('text')->all();

        if ($lista === []) {
            return null;
        }
        $i = min(max(0, $index), count($lista) - 1); // repete a ultima ao exceder

        return $lista[$i];
    }

    /** Substitui as variaveis pelo estado do incidente. */
    private function vars(string $texto, Incident $incident): string
    {
        $server = $incident->server()->withoutGlobalScopes()->first();

        return strtr($texto, [
            '{servidor}' => $server?->name ?? ('#'.$incident->server_id),
            '{ip}' => (string) ($server?->host ?? ''),      // campo "Host / IP" do servidor
            '{grupo}' => (string) ($server?->grupo ?? ''),
            '{metrica}' => self::METRIC_PT[$incident->metric] ?? (AlertRule::LABELS[$incident->metric] ?? $incident->metric),
            '{valor}' => $this->valor($incident),
            '{nivel}' => self::LEVEL_PT[$incident->level] ?? $incident->level,
            '{particao}' => (string) $incident->mount,
        ]);
    }

    private function valor(Incident $incident): string
    {
        if ($incident->value_at_fire === null) {
            return '';
        }
        if ($incident->metric === 'watchdog') {
            return ((int) $incident->value_at_fire).'s sem reportar';
        }

        return $incident->value_at_fire.($incident->metric === 'load' ? '/núcleo' : '%');
    }

    private function defaultFiring(Incident $incident): string
    {
        return self::defaultFiringTemplate($incident->level, $incident->mount !== null);
    }

    private function defaultResolved(Incident $incident): string
    {
        return self::defaultResolvedTemplate($incident->mount !== null);
    }
}
