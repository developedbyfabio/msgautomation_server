# Fatia 12 — Tags standalone: criar/gerir pelo botão Tags, atribuir depois — 2026-07-06

Git no início: HEAD `21811dc` (fatia 11), working tree limpo exceto os dois relatórios untracked
pré-existentes (fora do commit). Baseline: **765 verdes / 3001 assertions**.

---

## SURPRESA DO MAPEAMENTO (registrada e adaptada)

**Não existe "aba Tags" — e a gestão já existia quase inteira.** O "botão de Tags" que o dono citou
é o botão **Tags em /contatos**, que abre o modal **"Gerenciar tags"** (T-1). Esse modal já tinha:
lista das tags da conta, **renomear** (com unicidade), **trocar cor** e **excluir com confirmação
mostrando o uso** (contatos + regras + fluxos + regras de Kanban). O que faltava era exatamente a
reclamação do dono: **criar** — o empty state dizia literalmente "Crie pela primeira vez no painel
de um contato". **Adaptação consciente:** em vez de criar uma aba/rota nova duplicando a gestão, a
fatia adiciona a **criação standalone dentro do modal existente** (caminho único, sem UI
divergente) + **contagem de uso** na lista.

## Mapeamento (lido antes de escrever)

- **Model `Tag`:** `account_id`, `name` (40), `color` (paleta de 8: `Tag::COLORS`), com
  `BelongsToAccount` (escopo global por conta). **Sem campo de origem no model** — a origem vive no
  **pivot**.
- **Pivot `contact_tag`:** `contact_id`, `tag_id`, `origin` (`manual` | `board_rule` | `ai_intent`,
  default `manual`), `origin_ref`, timestamps, **unique (contact_id, tag_id)** e
  **`cascadeOnDelete` no tag_id** — excluir a tag limpa o pivot dela via FK.
- **Origem de criação manual hoje:** a criação em si não registra origem (não há coluna); o
  rastreio acontece no **attach** (`origin='manual'` na UI). Tag standalone nasce **sem pivot** e
  ganha `manual` quando atribuída — padrão preservado.
- **Unicidade:** DB `unique (account_id, name)` (case-sensitive) + validação de aplicação
  **`LOWER(name) = ?`** com `mb_strtolower` (case-insensitive), escopada por conta — mesmo padrão
  na criação inline (`ContactTags::addTag`) e no renomear (`Contatos::saveTags`). No **MySQL de
  produção** a collation ci também casa **sem acento** (achado da Fatia 8); os testes rodam em
  **sqlite :memory:**, então o teste prova o case-insensitive (garantido pelo código) e o
  comportamento de acento fica registrado como dependente da collation.

## O que o modal mostrava antes → agora

- **Antes:** lista (chip + input de renomear + select de cor + lixeira); exclusão com confirmação
  de uso; **sem criação**; empty state mandando criar num contato.
- **Agora:** formulário **"Nova tag"** no topo (nome + cor + Criar, `wire:submit`), validação
  server-side (obrigatório, ≤40, único por conta pelo padrão LOWER, cor restrita à paleta), criação
  escopada (BelongsToAccount preenche `account_id` da conta ativa); **"N contato(s)"** ao lado de
  cada tag (`withCount('contacts')` — uma query, sem N+1); empty state atualizado ("Crie a primeira
  aqui em cima"). Toast confirma e a tag entra na lista sem fechar o modal.

## Exclusão (escopo exato — inalterada, só conferida)

`deleteTagConfirmed()`: `Tag::query()->where('id', $id)->delete()` — **uma** tag, sob o escopo
`BelongsToAccount` (id de outra conta = no-op); o pivot **daquela tag apenas** é removido pela FK
`cascadeOnDelete`. Contatos, outras tags e seus pivots intactos. Confirmação obrigatória mostrando
o uso (já existia; agora o uso também aparece na lista antes de clicar).

## Atribuição (regressão garantida)

`ContactTags` (painel do contato em /contatos e /conversas) **sem nenhum diff** — criação inline
preservada como atalho. Tags criadas no modal aparecem na atribuição naturalmente (mesma fonte:
`Tag` escopada); o `addTag` reusa por nome case-insensitive em vez de duplicar (provado por teste).

## Testes

`tests/Feature/TagsStandaloneTest.php` (**novo**, 7 casos):
1. **Criar standalone** persiste na conta ativa com cor, **sem pivot**, e fica disponível na
   atribuição — o `addTag` do painel com outra caixa ("vip" vs "VIP") **reusa** a mesma tag e
   rastreia `origin='manual'` no pivot.
2. **Unicidade:** duplicata case-insensitive na mesma conta rejeitada sem criar; **mesma string em
   outra conta permitida** (unicidade é por conta).
3. **Renomear** reflete nos contatos que já têm a tag (mesma linha); renomear pra nome de outra tag
   da conta (case diferente) é rejeitado sem efeito.
4. **Excluir com uso:** confirmação exibe "2 contato(s)"; confirmar remove a tag e limpa **só** o
   pivot dela — a outra tag e seus vínculos intactos; **contatos permanecem**.
5. **Excluir sem uso:** confirmação simples ("0 contato(s)"), exclui normal.
6. **Posse/isolamento:** excluir por id forjado de outra conta = no-op; chave forjada no mapa de
   renomear é ignorada (saveTags só itera tags da conta).
7. **Contagem de uso** visível na lista do modal.

Regressão: `TagsTest` (16 casos, incluindo o renomear/excluir do modal e a criação inline) verde
**sem alteração**.

## Contagem de testes

| | testes | assertions |
|---|---|---|
| Antes | 765 | 3001 |
| Depois | **772** | **3033** |

Suíte inteira **sequencial**, tudo verde — **zero teste existente alterado** nesta fatia.

## Confirmações explícitas

- Diff de produção: **2 arquivos** — `app/Livewire/Contatos.php` (+`createTag`, props do form,
  `withCount` no render) e `resources/views/livewire/contatos.blade.php` (form + contagem).
  Pipeline, fluxos, Kanban, `ContactTags` e todo o resto: **sem diff**. Zero migration.
- Criação inline preservada; origin tracking preservado; isolamento por conta + posse server-side
  em toda ação por id.

## Commit
Local, sem push (Fabio empurra). Hash reportado na resposta.
