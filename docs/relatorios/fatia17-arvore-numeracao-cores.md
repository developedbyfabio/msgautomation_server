# Fatia 17 — Editor de fluxos: numeração por fluxo + cores de identidade + árvore — 2026-07-06

Git no início: HEAD `19efdeb` (adendo v2), working tree limpo exceto os dois relatórios untracked
pré-existentes (fora do commit). Baseline: **821 verdes / 3276 assertions**.

---

## Parte 1 — Diagnóstico (confirmado antes de codar)

**Causa confirmada:** o editor exibia `flow_nodes.id` — a **PK auto-increment GLOBAL da tabela**
(compartilhada entre fluxos e contas) — como número do nó. Não é corrupção: é identidade interna
vazando pra UI.

**Evidência do acúmulo em produção (conta 1, SELECT de leitura, ANTES da migration):**

| fluxo | nós (PKs) |
|---|---|
| #2 Atendimento (exemplo) | 2–7 |
| #4 Novo fluxo | 9, 13, 14 |
| #5 Clínica / consultório | **15–19** |
| #6 Salão de beleza / barbearia | **20–24** ← "o nó já está no 20" |
| #7 Comércio / estabelecimento | 25–29 |

**Pontos de exibição do número de nó (lista completa):**
1. Card do nó no editor — badge `no #{{ $node->id }}` (`fluxos.blade.php:282`);
2. Select de destino da opção — rótulo `no #id (kind)` (`:337`);
3. Seção "Visualizacao" (preview mono) — `[no #next_node_id]` (`:368`);
4. Warnings do detector (`Fluxos::flowWarnings`) — 5 mensagens "No #id ..." / "Opcao ... do no #id"
   (`Fluxos.php:661-676`).
O simulador (C.1) exibe só texto — sem número de nó. Nenhum outro ponto encontrado.

## Parte 2 — `display_number` por fluxo

- **Migration** `2026_07_06_000002_add_display_number_to_flow_nodes.php`: coluna
  `display_number` (unsignedInteger, nullable) + backfill idempotente (por fluxo, continua do
  `max(display_number)` já atribuído — 0 na primeira execução — e numera **só os null** 1..N na
  ordem de `id`; re-rodar é no-op; nunca reordena) + unique `(flow_id, display_number)`. `down()`
  remove só o que a migration adicionou.
- **Rodada em produção** (forward, foreground) e confirmada por leitura: **0 nulls**; fluxo #5
  agora `15→1, 16→2, 17→3, 18→4, 19→5`; fluxo #6 `20→1 ... 24→5`. PKs intocadas (FKs de
  opções/sessões/parents preservadas).
- **Hook `creating`** no `FlowNode` (choke point único, padrão do slug da Fatia 15):
  `display_number = max(display_number)+1` dentro do `flow_id`. Cobre editor (`novoFluxo`,
  `definirDestino` com `novo_*`), templates (Fatia 7), duplicação (Fatia 13) e seed.
- **Estabilidade:** deletar não renumera (buracos ficam — comportamento de issue tracker; o
  próximo é max+1). **Duplicação:** o `DuplicateFlow` já criava nós **sem** `display_number`
  (verificado — nenhuma cópia de atributo em massa) → o hook age e a cópia nasce com numeração
  **fresca e contígua** 1..N, independente de buracos do original (comportamento aceito: a cópia é
  um fluxo novo; original intacto — provado por teste).
- **UI:** os 4 pontos listados trocados para `#display_number`; o **value** dos selects e todas as
  FKs continuam sendo a **PK real** (provado por teste: `definirDestino` persiste o id do banco).

## Parte 3 — Cores de identidade

- **Helper único:** `FlowNode::identityColor()` + `FlowNode::IDENTITY_COLORS` — paleta fixa de 12
  (red, orange, amber, lime, green, teal, cyan, blue, indigo, violet, fuchsia, rose), cada uma como
  par claro/escuro **`bg-X-500 dark:bg-X-400`** (tom 500 no light, 400 no dark — contraste em
  ambos). `cor = paleta[(display_number − 1) % 12]` — determinística, zero persistência/escolha.
  Strings literais no model de propósito: o scanner do Tailwind v4 varre qualquer arquivo
  não-gitignored (classes confirmadas no bundle após `npm run build` foreground).
- **Onde aparece:** dot no badge do card do nó (junto ao `#N` — a cor NUNCA é o único indicador);
  **dot ao lado do select de destino** com a cor do nó alvo — escolha registrada:
  **server-rendered** a partir de `$opt->next_node_id` (o `definirDestino` já re-renderiza via
  Livewire; `<option>` de select nativo não coloriza de forma confiável, e Alpine seria redundante
  com o roundtrip que já existe); e em todos os nós/referências/órfãos da árvore.

## Parte 4 — Árvore (read-only)

- **Acesso:** alternância "Editar | Arvore" no topo do editor (`$treeView`, resetado ao
  entrar/sair do fluxo). A árvore herda a posse do editor (`editar()` usa `findOrFail` escopado
  por conta — provado por teste com fluxo de outra conta).
- **Estrutura:** a travessia é montada **em PHP** (`Fluxos::buildFlowTree`) produzindo um array
  aninhado; o Blade recursivo (`livewire/partials/flow-tree-node.blade.php`) só renderiza o array —
  nenhum estado compartilhado entre includes. Nó = dot + `no #N` + badge do kind (âmbar
  handoff / sky final / zinc menu) + trecho da message (`Str::limit(strip_tags(...), 80)`,
  escapado). Opções com o input que o contato digita + rótulo + seta pro destino (indentado com
  linha de conexão `border-l`). Opção sem destino/destino inválido → "— sem destino" inline.
- **Expand-once (a proteção formal):** set global `$visited` da DFS a partir da raiz; o nó marca
  visitado **antes** de descer (auto-laço também vira referência); reencontro renderiza
  `↩ volta ao ● no #N` sem expandir — cobre **ciclo** (o laço fica visível, que é o que o dono
  quer ver) e **DAG** (subárvore compartilhada não duplica). **Terminação:** cada nó expande no
  máximo 1 vez ⇒ a recursão é limitada a |nós do fluxo|; nenhum limite de profundidade arbitrário
  é necessário (destino fora do fluxo cai no `?? null` → "sem destino").
- **Órfãos:** após a travessia, nós fora do set visitado aparecem em "Nos nao conectados"
  (dot + `#N` + kind + trecho), em tom de atenção.
- **Opcional 5.5 (clicar → focar card no modo Editar): PULADO e registrado** — trocar de view é um
  re-render Livewire; rolar até a âncora depois do morph exigiria hook JS fora do padrão da página
  (não é trivial no padrão atual; não é requisito).

## Parte 5 — Hint do handoff

Corrigido: "move o card pra **Aguardando resposta**" (era "Em atendimento" — pendência registrada
nas fatias 15 e no adendo v2, nota 2). Só copy; provado por teste (novo texto renderiza, antigo
não aparece).

## Ajustes deliberados em testes (1, único)

- `FlowEditorHandoffTest::test_opcao_pode_apontar_pra_handoff_existente` — asseria o rótulo antigo
  `no #{PK} (handoff)` no select; agora asserta `no #{display_number} (handoff)`. O mesmo teste já
  prova (inalterado) que o `definirDestino` persistiu a **PK** — rótulo mudou, semântica de dados
  não. Nenhum outro teste asseria o rótulo antigo.

## Arquivos de teste e cobertura

- `FlowDisplayNumberTest` (**novo**, 8 casos): fluxo novo **começa em 1** mesmo com outros fluxos
  na tabela (o teste que mata o bug; asserta PK > 3 E display 1); deletar não renumera + próximo é
  max+1; unique por fluxo vigente; numeração independente entre contas; template 1..N e
  **duplicação com numeração fresca contígua + original intacto**; UI exibe `#N` e **value do
  select preserva a PK** (assertSeeHtml `value="{pk}"` + persistência via `definirDestino`);
  warnings falam `#N`; helper de cor determinístico com ciclo de 12 (nó 1 ≡ nó 13).
- `FlowTreeViewTest` (**novo**, 8 casos): estrutura do template (raiz numerada, rótulos de opção,
  badges, trechos); **laço para ancestral não trava** (render completa com `↩ volta ao` e a
  message da raiz aparecendo exatamente 1x no HTML); **DAG** com nó compartilhado expande 1x;
  órfão na seção "Nos nao conectados"; opção sem destino marcada inline; árvore **read-only**
  (sem "Salvar no"/ações de escrita no render); **posse** (fluxo de outra conta →
  `ModelNotFoundException`); hint do handoff novo renderiza e o antigo sumiu.

## Contagem de testes

| | testes | assertions |
|---|---|---|
| Antes | 821 | 3276 |
| Depois | **837** | **3317** |

Suíte inteira **sequencial**, tudo verde — zero regressão fora do único ajuste listado.

## Confirmações explícitas

- **Motor/pipeline/Kanban: zero diff** (conferido: `git diff app/Whatsapp/Flows/FlowEngine.php
  app/Jobs/ app/Kanban/` vazio). `next_node_id`/`parent_node_id`/`root_node_id` seguem guardando a
  PK real; nenhuma semântica de dados mudou.
- Migration única e aditiva (a do display_number), forward, foreground, backfill idempotente
  confirmado por leitura em produção. Assets rebuildados em foreground (classes de cor no bundle).
- Árvore sem lib JS externa (Blade recursivo + Tailwind; nem Alpine foi necessário — o dot do
  select é server-rendered).
- **`queue:restart` executado** (o `FlowNode` é carregado pelo `FlowEngine` nos jobs): worker novo
  no ar — **pid 34245, 10:12 de 2026-07-06** (o sinal é re-emitido após o commit, por disciplina;
  é idempotente). Workers do Nextgest intocados.

## Commit
Local, sem push (Fabio empurra). Hash reportado na resposta.
