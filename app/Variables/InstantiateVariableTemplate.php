<?php

namespace App\Variables;

use App\Models\Variable;

/**
 * Fatia 14 — materializa um template de variavel na conta ativa pelo caminho
 * OFICIAL (VariableWriter — mesmas guardas do CRUD de /variaveis: nome valido/
 * nao-reservado/unico, sem segredo, sem recursao). Nome duplicado NAO sufixa
 * (nome e identidade de referencia): o writer rejeita e o chamador mostra o
 * motivo — comportamento do caminho oficial, registrado na Fatia 14.
 *
 * @return array{variable: ?Variable, errors: array<string,string>, warnings: array<int,string>}
 */
class InstantiateVariableTemplate
{
    public function __construct(private VariableTemplateCatalog $catalog, private VariableWriter $writer)
    {
    }

    /** @throws \InvalidArgumentException key desconhecida */
    public function handle(string $key, int $accountId): array
    {
        $template = $this->catalog->get($key);

        return $this->writer->save($accountId, [
            'name' => $template['name'],
            'type' => $template['type'],
            'config' => $template['config'],
        ]);
    }
}
