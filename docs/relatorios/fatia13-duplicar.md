# Fatia 13 — Duplicar fluxo (deep copy) + duplicar campanha — 2026-07-06

Git no início: HEAD `6b94f85` (fatia 12), working tree limpo exceto os dois relatórios untracked
pré-existentes (fora do commit). Baseline: **772 verdes / 3033 assertions**.

---

## Mapeamento (lido antes de escrever)

- **`FlowOption`:** `flow_node_id`, `input`, `label`, **`next_node_id`** (a coluna de destino),
  `ordem`.
- **`FlowTrigger`:** `flow_id`, `match_type`, `match_value`, `precision`, `fuzzy_level` +
  `normalized_text` **recalculado automaticamente no `saving`** (MATCH-1) — a cópia só copia os
  campos de match; a normalização se refaz sozinha.
- **`Flow`:** `name`, `enabled`, `scope`, `timeout_seconds`, `invalid_message`, `root_node_id` +
  pivôs de escopo `flow_contacts` (scope 'contatos') e `flow_tag` (scope 'tags').
  **`FlowNode`:** `flow_id`, `parent_node_id`, `kind`, `message`, `ordem`.
- **`entryFlow` × `enabled` (item 3 do design): CONFIRMADO, sem bug.**
  `FlowEngine::entryFlow` (linha 54): `->where('account_id', $accountId)->where('enabled', true)`
  — fluxo desabilitado **não participa** do matching de entrada. A cópia com triggers idênticos e
  `enabled=false` não disputa nada com o original.
- **Campanha (`ProactiveCampaign`) — divisão campo a campo:**
  | copiado (conteúdo/público) | zerado/não copiado (execução) |
  |---|---|
  | `name` (sufixado) | `status` → forçado **`draft`** |
  | `message` | `start_at` → **null** (agenda é decisão do ciclo preview→aprovar; copiar data pode estar no passado) |
  | `optout_footer` | `approved_at` → null |
  | `audience_type` | `approved_by` → null |
  | `audience_config` (JSON, ids da mesma conta) | **`CampaignTarget`** (snapshot congelado da aprovação + status de envio) → **não copiado** |
- **Sufixo (Fatia 7):** `InstantiateFlowTemplate::uniqueName` — loop `" (2)", " (3)"...` por conta.
  Reusado com base `"{nome} (copia)"` nos dois lugares.
- **Listagens:** dropdown por item em `fluxos.blade.php` e `campanhas.blade.php` (item "Duplicar"
  entre Editar e Excluir); redirect pro editor = `Fluxos::editar($id)` (mesmo do `usarTemplate`).
  Campanha: a "tela de edição" é o modal de form — a cópia abre nele (`edit($nova->id)`).

## Serviço `App\Whatsapp\Flows\DuplicateFlow`

`handle(int $flowId, int $accountId)` — **posse no próprio serviço** (`firstOrFail` por
`account_id`, independente do caller) e **tudo em `DB::transaction`**:
1. Cria o `Flow` novo (nome sufixado, `enabled=false`, `root_node_id=null`).
2. 1ª passada: copia todos os nós construindo `$mapa[old_id] = new_id`.
3. 2ª passada: reescreve `parent_node_id` de cada nó pelo mapa.
4. Copia as opções reescrevendo `next_node_id` pelo mapa (null permanece null).
5. Copia os triggers (normalização se refaz no saving).
6. Copia os pivôs de escopo (contatos/tags) — **adição consciente além dos 4 itens listados**:
   são configuração do fluxo (não runtime); sem eles, uma cópia com scope 'contatos'/'tags'
   nasceria estruturalmente quebrada (escopo sem ninguém).
7. Seta `root_node_id` remapeado; commit.

Trecho essencial do remapeamento (aborta em referência quebrada — rollback total):

```php
private function doMapa(array $mapa, int $oldId, Flow $original, string $onde): int
{
    return $mapa[$oldId]
        ?? throw new \RuntimeException("Fluxo #{$original->id} com estrutura corrompida ({$onde} ...) — duplicacao abortada.");
}
```

Aplicado a `parent_node_id`, `next_node_id` de cada opção e `root_node_id` — **nenhuma referência
da cópia pode apontar pra nó do original**, e original corrompido nunca é copiado em silêncio (a
UI mostra o erro no toast). **Não** copia `FlowSession`; **não** toca `default_flow_id`.

## UI

- **Fluxos:** `Fluxos::duplicar()` (find escopado + serviço com posse própria — defesa dupla) →
  `editar($copia->id)` (redirect pro editor, padrão da Fatia 7) + toast "duplicado (desligado)".
- **Campanhas:** `Campanhas::duplicate()` (find escopado) → cria o rascunho limpo → abre no form
  de edição + toast. **Nenhum job/agendamento** — só o `create`.

## Testes

`tests/Feature/DuplicateFlowTest.php` (**novo**, 6 casos; fixture = template 'clinica' da Fatia 7,
que tem menu raiz + opções + final + **handoff** + gatilhos):
1. **Deep copy com remapeamento total** — contagens iguais (nós/opções/triggers); zero interseção
   de ids; `root_node_id` da cópia pertence à cópia (mesmo kind da raiz original); todo
   `parent_node_id` remapeado; **o teste crítico:** itera as opções da cópia e asserta que o
   `flow_id` de cada destino é o da cópia — nenhuma referência ao original; handoff copiado com a
   mesma mensagem; `normalized_text` vivo no trigger copiado.
2. Cópia nasce `enabled=false`; 2ª duplicação sufixa `" (copia) (2)"`.
3. **Original byte-idêntico** (snapshot completo de atributos + nós + opções + triggers antes ==
   depois); `default_flow_id` continua no original; `FlowSession` não copiada (runtime).
4. **Duplicar pela UI abre a cópia no editor sem quebrar**, incluindo o nó handoff
   (`nodeKind`/`nodeMsg` carregados — shape da 5b).
5. **Posse:** id de fluxo de outra conta rejeitado nos dois níveis (UI: nada criado; serviço:
   `ModelNotFoundException`).
6. **Isolamento A/B:** duplicar em A não cria nada em B.

`tests/Feature/DuplicateCampaignTest.php` (**novo**, 4 casos):
1. Original **aprovada** (com `start_at`, aprovador e 2 targets) → cópia `draft` com conteúdo e
   público iguais, execução zerada (start_at/approved_* null, **0 targets**); original intacta
   (status/agenda/aprovação/targets); cópia abre no form de edição.
2. Sufixo incremental em duplicação dupla.
3. **`Queue::fake` + `assertNothingPushed`** — duplicar nunca despacha job.
4. Posse + isolamento: id de outra conta é no-op (nada criado em lugar nenhum).

## Contagem de testes

| | testes | assertions |
|---|---|---|
| Antes | 772 | 3033 |
| Depois | **782** | **3087** |

Suíte inteira **sequencial**, tudo verde — **zero teste existente alterado**.

## Confirmações explícitas

- Diff de produção: `Fluxos.php` (+ação `duplicar`), `Campanhas.php` (+`duplicate`/`uniqueName`),
  os dois blades (+1 item de menu cada) e o serviço novo `DuplicateFlow`. **Pipeline, `FlowEngine`,
  editor (além do redirect via `editar()` existente), motor de dispatch: sem diff.** Zero migration.
- Duplicação nunca dispara envio, nunca altera o original, nunca seta `default_flow_id`, nunca
  copia sessão. Deep copy atômico; posse server-side nos dois fluxos de ação.

## Commit
Local, sem push (Fabio empurra). Hash reportado na resposta.
