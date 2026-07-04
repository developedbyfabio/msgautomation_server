<?php

namespace App\Whatsapp\Flows;

use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\FlowOption;
use Illuminate\Support\Facades\DB;

/**
 * Fatia 7 — instancia um template do FlowTemplateCatalog como fluxo REAL da conta:
 * Flow (enabled=true, pronto pra usar) + FlowTriggers + FlowNodes + FlowOptions,
 * todos escopados a conta informada, no MESMO shape que editor (5b) e motor (5)
 * esperam (root menu, opcoes ligadas por next_node_id, handoff terminal com message).
 *
 * NAO seta default_flow_id (o usuario escolhe o fluxo padrao em Configuracoes se
 * quiser usa-lo no modo automatico). Multiplas instancias do mesmo template sao
 * permitidas: colisao de nome ganha sufixo " (2)", " (3)"...
 */
class InstantiateFlowTemplate
{
    public function __construct(private FlowTemplateCatalog $catalog)
    {
    }

    /** @throws \InvalidArgumentException template desconhecido ou blueprint malformado */
    public function handle(string $key, int $accountId): Flow
    {
        $template = $this->catalog->get($key);
        $this->assertBlueprint($template);

        return DB::transaction(function () use ($template, $accountId) {
            $flow = Flow::create([
                'account_id' => $accountId,
                'name' => $this->uniqueName((string) $template['name'], $accountId),
                'enabled' => true,
                'scope' => 'global',
                'timeout_seconds' => (int) ($template['timeout_seconds'] ?? 600),
                'invalid_message' => $template['invalid_message'] ?? null,
            ]);

            foreach ($template['triggers'] as $t) {
                $flow->triggers()->create([
                    'match_type' => $t['type'],
                    'match_value' => $t['value'],
                    'precision' => 'exato',
                ]);
            }

            $ordem = 0;
            $root = $this->createNode($flow, $template['root'], null, $ordem);
            $flow->update(['root_node_id' => $root->id]);

            return $flow;
        });
    }

    /** Cria o no e (recursivo) os filhos das opcoes, ligando next_node_id. */
    private function createNode(Flow $flow, array $node, ?int $parentId, int &$ordem): FlowNode
    {
        $criado = FlowNode::create([
            'flow_id' => $flow->id,
            'parent_node_id' => $parentId,
            'kind' => $node['kind'],
            'message' => $node['message'],
            'ordem' => $ordem++,
        ]);

        foreach ($node['options'] ?? [] as $i => $opt) {
            $filho = $this->createNode($flow, $opt['node'], $criado->id, $ordem);
            FlowOption::create([
                'flow_node_id' => $criado->id,
                'input' => (string) $opt['input'],
                'label' => (string) $opt['label'],
                'next_node_id' => $filho->id,
                'ordem' => $i + 1,
            ]);
        }

        return $criado;
    }

    /** Nome do template; em colisao na conta, sufixa " (2)", " (3)"... */
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

    /**
     * Guarda contra blueprint malformado (falha ALTO antes de escrever qualquer
     * linha): raiz e menu COM opcoes (achado da Fatia 4), gatilho de entrada
     * presente (sem ele o fluxo nao poderia estar ligado), handoff/final terminais
     * e handoff com message (invariantes do editor da 5b).
     */
    private function assertBlueprint(array $template): void
    {
        $key = (string) ($template['key'] ?? '?');
        if (empty($template['triggers'])) {
            throw new \InvalidArgumentException("Template {$key}: precisa de ao menos um gatilho de entrada.");
        }
        $root = $template['root'] ?? null;
        if (! is_array($root) || ($root['kind'] ?? null) !== 'menu' || empty($root['options'])) {
            throw new \InvalidArgumentException("Template {$key}: a raiz deve ser um menu COM opcoes.");
        }
        $this->assertNode($key, $root);
    }

    private function assertNode(string $key, array $node): void
    {
        $kind = $node['kind'] ?? null;
        if (! in_array($kind, ['menu', 'final', 'handoff'], true)) {
            throw new \InvalidArgumentException("Template {$key}: kind invalido \"{$kind}\".");
        }
        if (in_array($kind, ['final', 'handoff'], true) && ! empty($node['options'])) {
            throw new \InvalidArgumentException("Template {$key}: no {$kind} e terminal — nao pode ter opcoes.");
        }
        if ($kind === 'handoff' && trim((string) ($node['message'] ?? '')) === '') {
            throw new \InvalidArgumentException("Template {$key}: handoff exige message (aviso ao contato).");
        }
        foreach ($node['options'] ?? [] as $opt) {
            if (! isset($opt['input'], $opt['label'], $opt['node'])) {
                throw new \InvalidArgumentException("Template {$key}: opcao sem input/label/node.");
            }
            $this->assertNode($key, $opt['node']);
        }
    }
}
