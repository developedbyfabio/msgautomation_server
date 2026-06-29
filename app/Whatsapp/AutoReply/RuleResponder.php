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
 *   {saudacao} -> "Bom dia" (05-11) / "Boa tarde" (12-17) / "Boa noite" (18-04)
 *   {data}     -> dd/mm/aaaa (fuso de exibicao)
 *   {hora}     -> HH:MM (fuso de exibicao)
 *
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

    /** Substitui placeholders no texto. */
    public function render(string $template, array $context = []): string
    {
        $now = $context['now'] ?? Carbon::now();
        if ($now instanceof Carbon) {
            $now = $now->copy()->setTimezone(config('app.display_timezone'));
        }

        $valores = [
            'nome' => trim((string) ($context['nome'] ?? '')),
            'saudacao' => $this->saudacao((int) $now->format('H')),
            'data' => $now->format('d/m/Y'),
            'hora' => $now->format('H:i'),
        ];

        return preg_replace_callback('/\{(\w+)\}/u', function ($m) use ($valores) {
            $chave = mb_strtolower($m[1], 'UTF-8');

            return array_key_exists($chave, $valores) ? $valores[$chave] : $m[0];
        }, $template);
    }

    private function saudacao(int $hora): string
    {
        return match (true) {
            $hora >= 5 && $hora <= 11 => 'Bom dia',
            $hora >= 12 && $hora <= 17 => 'Boa tarde',
            default => 'Boa noite',
        };
    }
}
