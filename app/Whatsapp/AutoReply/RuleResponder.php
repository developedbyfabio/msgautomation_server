<?php

namespace App\Whatsapp\AutoReply;

use App\Models\AutoReplyRule;
use Illuminate\Support\Carbon;

/**
 * S7 — resolve a resposta de uma regra NO ENVIO:
 *  1. escolhe UMA resposta (aleatoria entre as cadastradas) — varia a resposta,
 *     ajuda anti-ban (a Meta penaliza resposta identica repetida);
 *  2. processa placeholders no texto escolhido.
 *
 * Placeholders disponiveis (case-insensitive):
 *   {nome}     -> push_name do contato (fallback "" se vazio)
 *   {saudacao} -> variavel de SISTEMA editavel (V-1); fallback no default
 *                 historico identico ("Bom dia" 05-11 / "Boa tarde" 12-17 / "Boa noite")
 *   {data}     -> dd/mm/aaaa (fuso de exibicao)
 *   {hora}     -> HH:MM (fuso de exibicao)
 *   {palavra_sair} -> P-4: palavra de opt-out ATUAL das proativas (lookup nas
 *                 settings NO ENVIO — trocar a palavra muda ate campanha ja
 *                 aprovada); sem conta/valor resolve INTACTA
 *   {custom}   -> variaveis da CONTA (V-1: static | horario | dia_semana), cache
 *                 por conta invalidado em qualquer escrita; desconhecida/inativa
 *                 sai INTACTA (comportamento historico); resolucao em fuso SP,
 *                 SEMPRE so no envio (nunca antes do modelo de IA)
 *
 * Este e o renderizador UNICO (c620418): regras, nos de fluxo, IA-base, edicao
 * de pendencia, campanhas, testadores e previews — nada paralelo.
 * A escolha aleatoria e injetavel (chooser) para teste determinístico.
 */
class RuleResponder
{
    /** @var callable */
    private $chooser;

    public function __construct(?callable $chooser = null)
    {
        // Default: indice aleatorio uniforme.
        $this->chooser = $chooser ?? (fn (array $itens) => $itens[random_int(0, count($itens) - 1)]);
    }

    /** Resposta final pronta pra enviar (escolhida + placeholders), ou null. */
    public function responseFor(AutoReplyRule $rule, array $context = []): ?string
    {
        $escolhida = $this->pick($rule);
        if ($escolhida === null) {
            return null;
        }

        return $this->render($escolhida, $context);
    }

    /** Escolhe UMA das respostas cadastradas (aleatoria via chooser). */
    public function pick(AutoReplyRule $rule): ?string
    {
        $respostas = $rule->responseList()->values()->all();

        if ($respostas === []) {
            return null;
        }
        if (count($respostas) === 1) {
            return $respostas[0];
        }

        return ($this->chooser)($respostas);
    }

    /** Substitui placeholders no texto ({senha:...} NAO casa \w+ — fica pro Sender). */
    public function render(string $template, array $context = []): string
    {
        $now = $context['now'] ?? Carbon::now();
        if ($now instanceof Carbon) {
            $now = $now->copy()->setTimezone(config('app.display_timezone'));
        }

        $custom = $this->variaveisDaConta();

        $valores = [
            'nome' => trim((string) ($context['nome'] ?? '')),
            // V-1: saudacao resolve pela variavel de sistema quando existir
            // (default seeded = IDENTICO ao match historico); fallback no codigo.
            'saudacao' => isset($custom['saudacao'])
                ? $this->resolveVariavel($custom['saudacao'], $now)
                : $this->saudacao((int) $now->format('H')),
            'data' => $now->format('d/m/Y'),
            'hora' => $now->format('H:i'),
        ];

        $texto = preg_replace_callback('/\{(\w+)\}/u', function ($m) use ($valores, $custom, $now) {
            $chave = mb_strtolower($m[1], 'UTF-8');

            if (array_key_exists($chave, $valores)) {
                return $valores[$chave];
            }
            // P-4: {palavra_sair} = sistema (nome reservado), lookup LAZY nas
            // settings — so consulta quando o template usa; sem valor, INTACTA.
            if ($chave === 'palavra_sair') {
                return $this->palavraSair() ?? $m[0];
            }
            // V-1: variaveis custom ATIVAS da conta; desconhecida/inativa INTACTA.
            if (isset($custom[$chave])) {
                return $this->resolveVariavel($custom[$chave], $now);
            }

            return $m[0];
        }, $template);

        // Fatia 15 — {kb:slug}: conteudo de conhecimento REFERENCIAVEL da conta
        // do contexto (mesmo escopo das custom). Passe SEPARADO ({kb:...} tem ':'
        // e nao casa \w+) e DEPOIS do principal: o conteudo inserido e LITERAL —
        // {refs} dentro dele NAO resolvem (mesma filosofia sem-recursao do
        // VariableWriter). Orfao/sensivel/restrito/com-senha = STRING VAZIA +
        // warning (token literal NUNCA vaza pro contato; envio nunca quebra).
        return preg_replace_callback(
            '/\{kb:([a-z0-9_-]+)\}/iu',
            fn ($m) => $this->conteudoKb(mb_strtolower($m[1], 'UTF-8')),
            $texto,
        );
    }

    /** Fatia 15 — conteudo do conhecimento referenciavel, ou '' (orfao logado). */
    private function conteudoKb(string $slug): string
    {
        try {
            $accountId = app(\App\Tenancy\AccountContext::class)->id();
        } catch (\App\Tenancy\MissingAccountContextException) {
            \Illuminate\Support\Facades\Log::warning('{kb:} sem contexto de conta — substituido por vazio.', ['slug' => $slug]);

            return '';
        }

        $kb = \App\Models\Knowledge::query()->referenciavel($accountId)->where('slug', $slug)->first();

        // Guarda de segredo (coerente com S5): conteudo com {senha:...} seria
        // resolvido DEPOIS pelo Sender e vazaria por caminho nao-escopado — trata
        // como orfao. Inexistente/desativado/sensivel/restrito idem.
        if ($kb === null || app(\App\Whatsapp\Secrets\SecretVault::class)->hasRef((string) $kb->content)) {
            \Illuminate\Support\Facades\Log::warning('{kb:' . $slug . '} nao resolvido (inexistente, inativo, sensivel, restrito ou com segredo) — substituido por vazio.', [
                'account_id' => $accountId,
            ]);

            return '';
        }

        return (string) $kb->content;
    }

    /** Fallback historico da saudacao (conta sem a variavel de sistema seeded). */
    private function saudacao(int $hora): string
    {
        return match (true) {
            $hora >= 5 && $hora <= 11 => 'Bom dia',
            $hora >= 12 && $hora <= 17 => 'Boa tarde',
            default => 'Boa noite',
        };
    }

    /**
     * P-4 — palavra de opt-out ATUAL da conta do contexto, lida das settings a
     * CADA render (sem cache de proposito: trocar a palavra em /configuracoes
     * muda o rodape ate de campanha JA aprovada, no proximo envio).
     */
    private function palavraSair(): ?string
    {
        try {
            $accountId = app(\App\Tenancy\AccountContext::class)->id();
        } catch (\App\Tenancy\MissingAccountContextException) {
            return null;
        }

        $palavra = trim((string) \App\Models\AutoReplySetting::withoutAccountScope()
            ->where('account_id', $accountId)->value('proactive_optout_word'));

        return $palavra !== '' ? $palavra : null;
    }

    // ---- V-1: resolucao das variaveis configuraveis ---------------------------

    /**
     * Variaveis ATIVAS da conta do contexto, cacheadas por conta (o observer do
     * model invalida em QUALQUER escrita). Sem contexto/conta: so nativas.
     *
     * @return array<string,array{type:string,config:array}>
     */
    private function variaveisDaConta(): array
    {
        try {
            $accountId = app(\App\Tenancy\AccountContext::class)->id();
        } catch (\App\Tenancy\MissingAccountContextException) {
            return [];
        }

        return \Illuminate\Support\Facades\Cache::remember(
            'variaveis:' . $accountId,
            3600,
            fn () => \App\Models\Variable::withoutAccountScope()
                ->where('account_id', $accountId)
                ->where('active', true)
                ->get(['name', 'type', 'config'])
                ->mapWithKeys(fn ($v) => [(string) $v->name => ['type' => (string) $v->type, 'config' => (array) $v->config]])
                ->all(),
        );
    }

    /** Resolve UMA variavel pro instante $now (ja no fuso de exibicao). */
    private function resolveVariavel(array $var, Carbon $now): string
    {
        $config = $var['config'];

        return match ($var['type']) {
            'static' => (string) ($config['valor'] ?? ''),
            'horario' => $this->resolveHorario($config, $now),
            'dia_semana' => $this->resolveDiaSemana($config, $now),
            default => '',
        };
    }

    /** Primeira faixa que cobre a hora atual vence; faixa pode cruzar meia-noite. */
    private function resolveHorario(array $config, Carbon $now): string
    {
        $hora = $now->format('H:i');
        foreach ((array) ($config['faixas'] ?? []) as $faixa) {
            $inicio = (string) ($faixa['inicio'] ?? '');
            $fim = (string) ($faixa['fim'] ?? '');
            if ($inicio === '' || $fim === '') {
                continue;
            }
            $dentro = $inicio <= $fim
                ? ($hora >= $inicio && $hora <= $fim)
                : ($hora >= $inicio || $hora <= $fim); // cruza meia-noite
            if ($dentro) {
                return (string) ($faixa['valor'] ?? '');
            }
        }

        return (string) ($config['valor_padrao'] ?? '');
    }

    private function resolveDiaSemana(array $config, Carbon $now): string
    {
        $dias = [1 => 'seg', 2 => 'ter', 3 => 'qua', 4 => 'qui', 5 => 'sex', 6 => 'sab', 7 => 'dom'];
        $dia = $dias[$now->dayOfWeekIso] ?? '';

        $valor = $config[$dia] ?? null;

        return $valor !== null && $valor !== '' ? (string) $valor : (string) ($config['valor_padrao'] ?? '');
    }
}
