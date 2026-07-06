# Fatia 18 — Árvore editável + visão Fluxograma (Mermaid) + botões do simulador — 2026-07-06

Git no início: HEAD `f38e51f` (fatia 17), working tree limpo exceto os dois relatórios untracked
pré-existentes (fora do commit). Baseline: **837 verdes / 3317 assertions**.

---

## Mapeamento (lido antes de escrever)

- **Actions reusadas pelo modal (nomes reais da 5b):** `salvarNo(int $nodeId)` (persiste
  `nodeMsg[$id]` + `nodeKind[$id]`, com as guardas de handoff) e `salvarOpcao(int $optId)`
  (persiste `optBuf[$id]['input'|'label']`) — ambas via `ownNode`/`ownOption` (posse por conta).
  Os buffers por id já são carregados no `editar()` (`loadNodeBuffers`), então o modal só faz
  `wire:model` nos MESMOS buffers e chama as MESMAS actions. **Única mudança numa action
  existente:** `salvarNo` passou de `void` a `bool` (false = validação rejeitou) para o modal
  saber se fecha — assinatura aditiva, blade ignora retorno, comportamento intocado.
- **Vite:** setup padrão (`laravel-vite-plugin`) — dynamic import/code-splitting nativos.
  **Não havia `wire:ignore` no projeto** — padrão estabelecido nesta fatia.
- **Simulador:** os três controles eram `<button>` estilizados como texto (`hover:underline`),
  disparando `toggleSimReveal` / `iniciarSim` / `fecharSim` — actions intocadas.

## Parte A — Modal de edição rápida na árvore

- Botão lápis (`aria-label`/`title`) em cada nó **expandido** da árvore e em cada **órfão**
  (não em referências `↩` — apontam pra nó já renderizado). `abrirEdicaoNo` valida posse
  (`ownNode`) no novo entry point; id alheio = no-op.
- Modal: header `● nó #N` + badge do kind; textarea da `message` (buffer `nodeMsg`); se menu,
  inputs dos **rótulos** das opções (buffer `optBuf.*.label`, com o input do contato como prefixo
  read-only). **Estrutura não entra** (aviso no próprio modal aponta pra Edição completa).
- `salvarEdicaoNo`: `salvarNo()` primeiro — **se a validação existente rejeitar (ex.: handoff sem
  message), o modal FICA aberto e nada persiste** (o toast de erro já é o surface existente);
  sucesso → `salvarOpcao()` de cada opção → fecha. A árvore re-renderiza do banco.
- **"Edição completa"**: alterna pro modo Editar. **Foco/scroll no card correspondente: PULADO e
  registrado** (mesma razão da 17: scroll pós-morph do Livewire exigiria hook JS fora do padrão).

## Parte B — Fluxograma (Mermaid)

- **`FlowMermaidBuilder`** (server-side, unit-testável) gera `flowchart TD` da mesma fonte da
  árvore. Mapeamento shape×kind: `menu` → losango `n{"..."}`; `final` → terminal `n(["..."])`;
  `handoff` → subrotina `n[["..."]]`. Cada nó declarado **uma vez**; opção → aresta rotulada
  (`-->|"1 - Agendar consulta"|`); **laço = só uma aresta a mais** (o Mermaid roteia a volta);
  **órfãos** declarados sem arestas (aparecem soltos). Rótulo: `#N · trecho` (~60 chars).
  Opção **sem destino não gera aresta** (registrado: a árvore e o detector já sinalizam; seta pro
  nada não existe em grafo). Exemplo real (template clínica, resumido):

```
flowchart TD
    n1{"#1 · Olá! Seja bem-vindo(a) à nossa clínica. Como podemos ajud…"}
    n2[["#2 · Perfeito! Vou te transferir para um atendente que finali…"]]
    n3(["#3 · Atendemos os principais convênios e também consultas par…"])
    ...
    n1 -->|"1 - Agendar consulta"| n2
    n1 -->|"2 - Convênios e valores"| n3
    ...
    style n1 fill:transparent,stroke:#ef4444,stroke-width:2px
```

- **Sanitização (regra):** labels sempre QUOTED; texto de usuário passa por normalização de
  espaços/quebras + substituição de todo caractere de sintaxe (`"` `'` `` ` `` `[` `]` `{` `}`
  `|` `<` `>` `#` `;`) por equivalentes inofensivos + truncamento. Provado por teste que nenhum
  desses caracteres sobrevive dentro do label. No front, `securityLevel: 'strict'` sempre.
- **Cores:** `FlowNode::IDENTITY_HEX` (tons 500 da MESMA paleta/ciclo da 17) + `identityHex()`;
  na DSL entram como `style nID fill:transparent,stroke:{hex},stroke-width:2px` — stroke colorido
  com fill transparente: o texto herda a cor do tema Mermaid (legível no claro e no escuro), e o
  `#N` está no rótulo (cor nunca é o único indicador). Mesmo nó = mesma cor nas três visões.
- **Wiring Livewire×Mermaid (mecanismo registrado):** container `wire:ignore`
  (`#fluxograma-canvas`, o SVG vive fora do morph) + evento `$this->dispatch('fluxograma-render',
  dsl: ...)` emitido pela action `setView('fluxograma')`; listener em `app.js` faz **dynamic
  import** do mermaid, `initialize({securityLevel:'strict', theme: dark?…})` e `render()` →
  `innerHTML`. **Re-render a cada abertura da aba** — como o fluxograma é read-only, editar exige
  sair dele, então o estado é sempre fresco ao voltar (cobre "após edição" por construção).
  Alternância de tema com a aba aberta: reabrir a aba re-renderiza (registrado; hook de tema não
  foi adicionado).
- **Bundle:** mermaid **11.16.0** via npm. Bundle principal `app.js`: 22,88 kB → **25,53 kB**
  (+2,6 kB — só o listener; Mermaid NÃO entra no principal). Chunks lazy do Mermaid: núcleo
  `mermaid.core` ~35 kB (gzip 11,7 kB) + chunks do renderer de flowchart (maior: ~648 kB raw)
  baixados **só no primeiro acesso** à aba; katex/cytoscape são chunks de outros tipos de
  diagrama que o flowchart não carrega.

## Parte C — Botões do simulador

Antes: três `<button>` com cara de texto (`text-xs hover:underline`). Depois: botões compactos no
padrão da Fatia 10 — borda + hover + ícone + `aria-label`/`title`: olho/olho-cortado
(revelar/mascarar senha), `arrow-path` (reiniciar), `x-mark` (fechar). Mesmo agrupamento/posição;
**zero mudança de comportamento** (mesmas actions).

## Ajustes deliberados em testes (2, um a um)

1. `FlowTreeViewTest::abrirArvore` (helper): `set('treeView', true)` → `call('setView','arvore')`
   — a alternância virou `viewMode` de 3 estados; o bool da 17 deixou de existir.
2. `FlowTreeViewTest::test_arvore_renderiza_estrutura...`: título asserido perdeu o
   "(somente leitura)" — a árvore ganhou edição rápida (a estrutura segue read-only).

## Arquivos de teste e cobertura

- `FlowTreeEditModalTest` (**novo**, 6): abrir carrega valores atuais dos buffers e salvar
  persiste pelas actions (message + rótulo; árvore reflete); **validação existente vale** (handoff
  sem message → rejeitado, modal fica aberto, nada persiste); **posse** no novo entry point (abrir
  com nó alheio = no-op; salvar forjado com `treeEditNodeId` alheio setado na marra = intacto);
  "Edição completa" alterna; fluxograma dispara `fluxograma-render` com DSL e é read-only
  (`assertDontSee('Salvar no')`); botões do simulador com `aria-label`.
- `FlowMermaidBuilderTest` (**novo**, 6): shapes por kind + `#N` + aresta rotulada (template
  clínica); **ciclo: declaração única + aresta de volta**; órfão declarado sem arestas;
  **sanitização** (11 caracteres perigosos não sobrevivem no label; truncamento); hex do ciclo de
  12 (nós 1 e 13 iguais, batendo com `identityHex()`); isolamento (nó de outro fluxo nunca entra
  na DSL — nem via destino corrompido).

## Contagem de testes

| | testes | assertions |
|---|---|---|
| Antes | 837 | 3317 |
| Depois | **849** | **3371** |

Suíte inteira **sequencial**, tudo verde — zero regressão fora dos 2 ajustes listados.

## Confirmações explícitas

- **Motor/pipeline/Kanban: zero diff** (`git diff app/Whatsapp/Flows/FlowEngine.php app/Jobs/
  app/Kanban/` vazio); **lógica do simulador intocada** (nenhuma linha de `iniciarSim`/
  `enviarSim`/sim* no diff do componente).
- **Escrita só pelas actions existentes** (salvarNo/salvarOpcao; nenhuma action de escrita nova —
  `abrirEdicaoNo`/`salvarEdicaoNo` só orquestram e delegam). Zero migration.
- Mermaid **sem CDN** (npm + dynamic import; bundle principal +2,6 kB apenas). Builds foreground.
- **`queue:restart` executado após o commit** — worker novo confirmado (pid/horário na resposta;
  o FlowNode é carregado pelo FlowEngine nos jobs).

## Commit
Local, sem push (Fabio empurra). Hash reportado na resposta.
