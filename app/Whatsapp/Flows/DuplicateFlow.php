<?php

namespace App\Whatsapp\Flows;

use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\FlowOption;
use Illuminate\Support\Facades\DB;

/**
 * Fatia 13 — DEEP COPY de um fluxo: Flow + todos os nos + todas as opcoes +
 * todos os gatilhos, com REMAPEAMENTO COMPLETO de ids (root_node_id,
 * parent_node_id e destino de cada opcao reescritos pelo mapa old->new).
 * Nenhuma referencia da copia aponta pra no do original.
 *
 * - A copia nasce enabled=false (sem disputa de matching: entryFlow filtra
 *   enabled=true — verificado no FlowEngine::entryFlow). Nome sufixado
 *   " (copia)" + " (2)", " (3)"... em colisao (mesmo mecanismo da Fatia 7).
 * - Pivos de ESCOPO (contatos/tags) tambem sao copiados — configuracao do
 *   fluxo, nao runtime; sem eles um escopo 'contatos'/'tags' nasceria quebrado.
 * - NAO copia FlowSession (runtime) e NAO toca default_flow_id.
 * - ATOMICO: tudo em DB::transaction. Referencia fora do mapa (estrutura
 *   corrompida no original) ABORTA com erro claro — nunca copia corrompido.
 * - Posse: so duplica fluxo DA CONTA informada (firstOrFail escopado aqui,
 *   independente do caller — acao Livewire e forjavel).
 */
class DuplicateFlow
{
    public function handle(int $flowId, int $accountId): Flow
    {
        $original = Flow::withoutAccountScope()
            ->where('account_id', $accountId)->whereKey($flowId)->firstOrFail();

        return DB::transaction(function () use ($original, $accountId) {
            $copia = Flow::create([
                'account_id' => $accountId,
                'name' => $this->uniqueName($original->name . ' (copia)', $accountId),
                'enabled' => false, // NUNCA nasce ligada (usuario edita e liga)
                'scope' => $original->scope,
                'timeout_seconds' => $original->timeout_seconds,
                'invalid_message' => $original->invalid_message,
                'root_node_id' => null, // remapeado no fim
            ]);

            // 1a passada: copia os nos e constroi o mapa old_id -> new_id.
            $mapa = [];
            $nos = $original->nodes()->with('options')->orderBy('id')->get();
            foreach ($nos as $no) {
                $novo = FlowNode::create([
                    'flow_id' => $copia->id,
                    'parent_node_id' => null, // remapeado na 2a passada
                    'kind' => $no->kind,
                    'message' => $no->message,
                    'ordem' => $no->ordem,
                ]);
                $mapa[$no->id] = $novo->id;
            }

            // 2a passada: parent_node_id pelo mapa (fora do mapa = corrompido, aborta).
            foreach ($nos as $no) {
                if ($no->parent_node_id === null) {
                    continue;
                }
                FlowNode::whereKey($mapa[$no->id])->update([
                    'parent_node_id' => $this->doMapa($mapa, (int) $no->parent_node_id, $original, "parent_node_id do no #{$no->id}"),
                ]);
            }

            // Opcoes: destino (next_node_id) reescrito pelo mapa.
            foreach ($nos as $no) {
                foreach ($no->options as $opt) {
                    FlowOption::create([
                        'flow_node_id' => $mapa[$no->id],
                        'input' => $opt->input,
                        'label' => $opt->label,
                        'next_node_id' => $opt->next_node_id === null
                            ? null
                            : $this->doMapa($mapa, (int) $opt->next_node_id, $original, "destino da opcao #{$opt->id}"),
                        'ordem' => $opt->ordem,
                    ]);
                }
            }

            // Gatilhos (normalized_text recalculado pelo saving do model).
            foreach ($original->triggers()->get() as $t) {
                $copia->triggers()->create([
                    'match_type' => $t->match_type,
                    'match_value' => $t->match_value,
                    'precision' => $t->precision,
                    'fuzzy_level' => $t->fuzzy_level,
                ]);
            }

            // Pivos de escopo (configuracao, nao runtime).
            $copia->contacts()->sync($original->contacts()->pluck('contacts.id')->all());
            $copia->tags()->sync($original->tags()->pluck('tags.id')->all());

            // Raiz remapeada por ultimo (copia completa antes de apontar).
            if ($original->root_node_id !== null) {
                $copia->update(['root_node_id' => $this->doMapa($mapa, (int) $original->root_node_id, $original, 'root_node_id')]);
            }

            return $copia;
        });
    }

    /** Id remapeado; fora do mapa = referencia quebrada no ORIGINAL -> aborta (rollback). */
    private function doMapa(array $mapa, int $oldId, Flow $original, string $onde): int
    {
        return $mapa[$oldId]
            ?? throw new \RuntimeException("Fluxo #{$original->id} com estrutura corrompida ({$onde} aponta pro no #{$oldId}, fora do fluxo) — duplicacao abortada.");
    }

    /** MESMO mecanismo da Fatia 7 (InstantiateFlowTemplate::uniqueName). */
    private function uniqueName(string $base, int $accountId): string
    {
        $nome = $base;
        $n = 2;
        while (Flow::withoutAccountScope()->where('account_id', $accountId)->where('name', $nome)->exists()) {
            $nome = "{$base} ({$n})";
            $n++;
        }

        return $nome;
    }
}
