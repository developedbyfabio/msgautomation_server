<?php

namespace App\Ai;

use App\Models\Knowledge;

/**
 * Fatia 14 — materializa um template de conhecimento na conta ativa pelo
 * caminho OFICIAL (KnowledgeWriter — mesmas guardas do CRUD de /conhecimento).
 * Titulo sufixado em colisao (mecanismo uniqueName da Fatia 7). Nasce
 * active=true e sensitivity 'low' (conteudo passivo e publico).
 */
class InstantiateKnowledgeTemplate
{
    public function __construct(private KnowledgeTemplateCatalog $catalog, private KnowledgeWriter $writer)
    {
    }

    /** @throws \InvalidArgumentException key desconhecida  @throws \RuntimeException template invalido no writer */
    public function handle(string $key, int $accountId): Knowledge
    {
        $template = $this->catalog->get($key);

        $res = $this->writer->save($accountId, [
            'title' => $this->uniqueTitle($template['name'], $accountId),
            'content' => $template['content'],
            'sensitivity' => 'low',
            'active' => true,
            'contact_ids' => [],
        ]);

        if ($res['errors'] !== []) {
            throw new \RuntimeException("Template de conhecimento '{$key}' rejeitado pelo KnowledgeWriter: " . implode('; ', $res['errors']));
        }

        return $res['knowledge'];
    }

    /** MESMO mecanismo da Fatia 7: sufixo " (2)", " (3)"... em colisao na conta. */
    private function uniqueTitle(string $base, int $accountId): string
    {
        $titulo = $base;
        $n = 2;
        while (Knowledge::withoutAccountScope()->where('account_id', $accountId)->where('title', $titulo)->exists()) {
            $titulo = "{$base} ({$n})";
            $n++;
        }

        return $titulo;
    }
}
