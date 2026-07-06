<?php

namespace App\Whatsapp\AutoReply;

use App\Models\AutoReplyRule;

/**
 * Fatia 14 — materializa um template de regra na conta ativa pelo caminho
 * OFICIAL (RuleWriter — as mesmas guardas do CRUD de /regras e da promocao).
 *
 * A regra NASCE DESABILITADA (enabled=false): os textos tem placeholders
 * [editaveis]; o usuario troca e liga. Colisao de gatilho com regra existente
 * NAO e bloqueada pelo caminho oficial (o RuleWriter grava; o detector de
 * conflito da UI avisa) — como a copia nasce OFF, nao ha disputa de matching
 * ate o usuario habilitar conscientemente.
 */
class InstantiateRuleTemplate
{
    public function __construct(private RuleTemplateCatalog $catalog, private RuleWriter $writer)
    {
    }

    /** @throws \InvalidArgumentException key desconhecida  @throws \RuntimeException template invalido no writer */
    public function handle(string $key, int $accountId): AutoReplyRule
    {
        $template = $this->catalog->get($key);

        $res = $this->writer->save($accountId, [
            'triggers' => array_map(fn ($t) => $t + ['precision' => 'exato'], $template['triggers']),
            'responses' => $template['responses'],
            'enabled' => false, // NUNCA nasce ligada (placeholders no texto)
            'cooldown_mode' => 'global',
            'cooldown_minutes' => null,
            'scope' => 'global',
            'contact_ids' => [],
            'ai_match_enabled' => false,
            'ai_examples' => [],
        ]);

        if ($res['errors'] !== []) {
            // Template do catalogo deveria ser sempre valido — falha ALTO.
            throw new \RuntimeException("Template de regra '{$key}' rejeitado pelo RuleWriter: " . implode('; ', $res['errors']));
        }

        return $res['rule'];
    }
}
