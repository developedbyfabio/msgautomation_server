<?php

namespace App\Whatsapp\Flows;

use App\Models\Flow;
use App\Models\FlowNode;
use Illuminate\Support\Str;

/**
 * Fatia 18 — gera a DSL do Mermaid (flowchart TD) pro fluxo, SERVER-SIDE e
 * unit-testavel. E aqui que mora a logica do fluxograma; o front so renderiza
 * (dynamic import do mermaid, securityLevel strict).
 *
 * Mapeamento visual (referencias do dono):
 *  - menu    -> losango de decisao  n{"..."}
 *  - final   -> terminal/estadio    n(["..."])
 *  - handoff -> subrotina destacada n[["..."]]
 *  - opcao   -> aresta rotulada com o texto da opcao ("1 - Horario")
 *  - laco    -> cada no e declarado UMA vez; ciclo e so uma aresta a mais
 *               (o proprio Mermaid roteia a seta de volta — sem duplicacao)
 *  - orfaos  -> declarados sem arestas (aparecem soltos ao lado)
 *
 * Rotulo do no: "#N · trecho" (display_number SEMPRE presente — cor nunca e o
 * unico indicador), trecho truncado ~60 e SANITIZADO: as messages sao texto de
 * usuario e entram na string do grafo — tudo que quebra/injeta sintaxe Mermaid
 * (aspas, colchetes, chaves, pipes, <>, crases, quebras de linha) e normalizado.
 * Cor de identidade via `style` (stroke colorido + fill transparente — o texto
 * fica com a cor do tema, legivel no claro e no escuro).
 *
 * Opcao SEM destino nao gera aresta (a arvore e o detector ja sinalizam; no
 * grafo uma seta pro nada nao existe) — registrado no relatorio.
 */
class FlowMermaidBuilder
{
    public function build(Flow $flow): string
    {
        $nodes = FlowNode::query()->where('flow_id', $flow->id)->with('options')->orderBy('id')->get();
        $ids = $nodes->pluck('id')->flip();

        $linhas = ['flowchart TD'];

        // 1) Declaracoes: cada no UMA vez, shape por kind, rotulo sanitizado.
        foreach ($nodes as $n) {
            $rotulo = $this->rotulo($n);
            $linhas[] = '    ' . match ($n->kind) {
                'final' => "n{$n->id}([\"{$rotulo}\"])",
                'handoff' => "n{$n->id}[[\"{$rotulo}\"]]",
                default => "n{$n->id}{\"{$rotulo}\"}",
            };
        }

        // 2) Arestas rotuladas (so destinos validos DENTRO do fluxo).
        foreach ($nodes as $n) {
            foreach ($n->options as $opt) {
                if ($opt->next_node_id === null || ! $ids->has((int) $opt->next_node_id)) {
                    continue; // sem destino/fora do fluxo: sem aresta
                }
                $etiqueta = $this->sanitiza(trim($opt->input . ' - ' . ($opt->label ?: '(sem rotulo)')), 40);
                $linhas[] = "    n{$n->id} -->|\"{$etiqueta}\"| n{$opt->next_node_id}";
            }
        }

        // 3) Cores de identidade: stroke com o MESMO hex das outras visoes;
        //    fill transparente (texto herda a cor do tema — legivel nos dois).
        foreach ($nodes as $n) {
            $linhas[] = "    style n{$n->id} fill:transparent,stroke:{$n->identityHex()},stroke-width:2px";
        }

        return implode("\n", $linhas);
    }

    /** Rotulo do no: "#N · trecho" (o numero SEMPRE presente). */
    private function rotulo(FlowNode $n): string
    {
        return '#' . $n->display_number . ' · ' . $this->sanitiza((string) $n->message, 60);
    }

    /**
     * Sanitiza texto de usuario pra dentro de um label QUOTED do Mermaid:
     * normaliza espacos/quebras, troca todo caractere de sintaxe (aspas,
     * colchetes, chaves, pipes, <>, crases, hash, ponto-e-virgula) por
     * equivalentes inofensivos e trunca. Nada de message crua na DSL.
     */
    private function sanitiza(string $texto, int $max): string
    {
        $t = preg_replace('/\s+/u', ' ', trim($texto)) ?? '';
        $t = str_replace(
            ['"', "'", '`', '[', ']', '{', '}', '|', '<', '>', '#', ';'],
            ['’', '’', '’', '(', ')', '(', ')', '/', '(', ')', 'nº', ','],
            $t,
        );

        return Str::limit($t, $max, '…');
    }
}
