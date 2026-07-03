# Prompt 07 — Menu lateral (sidebar) responsivo colapsável — 2026-07-03

**Status: ENTREGUE.** Baseline 577 verdes → final **580 verdes** (2188 assertions, sequencial),
`TenantIsolationTest` incluso. Nenhuma página/feature tocada — só o layout `app.blade.php`,
uma linha no `app.css` e um teste novo de smoke.

## Caminho escolhido: Flux FREE nativo (não precisou de Alpine custom)

A dúvida do prompt era se `flux:sidebar` é PRO. **Verifiquei no pacote instalado
(`livewire/flux` v2.15.0) antes de escrever qualquer coisa:**

- Os stubs `sidebar/*` (index, toggle, collapse, header, brand, nav, item, spacer, backdrop),
  `navlist/badge` e `breadcrumbs/*` estão em `vendor/livewire/flux/stubs/resources/views/flux/`
  — ou seja, vêm no pacote free.
- O custom element `ui-sidebar` (JS que faz colapso/overlay/persistência) está definido no
  **`flux-lite.min.js`**, que é o bundle servido a quem **não** tem licença Pro
  (`AssetManager.php:47` — sem Pro instalado serve o lite). Testado: o elemento e toda a
  máquina de estados estão lá.

Conclusão: sidebar é free nesta versão → usei os componentes nativos (responsividade,
acessibilidade, tooltips e persistência já resolvidos pelo Flux). Zero gambiarra Alpine.

## O que mudou

`resources/views/components/layouts/app.blade.php` (único arquivo de UI alterado):

- **Antes:** `<header>` horizontal com 14 links + cluster de estado; estourava em telas
  menores (rolagem horizontal) e espremia a conversa no celular.
- **Agora:** grid do Flux (`sidebar | header / main`, CSS já no `flux.css`):
  - `flux:sidebar sticky collapsible` na esquerda com os **mesmos 14 itens, mesmos ícones,
    mesmos rótulos, mesmas rotas** (`wire:navigate` preservado). Badges de Kanban (novos) e
    Revisão (pendências) idem — âmbar, `99+`. Quando retraída, a badge some e entra um
    **pontinho no ícone** (`icon-dot`) + tooltip com o rótulo no hover.
  - Item **ativo** destacado via detecção nativa do Flux (`data-current` pela URL atual —
    confirmado no HTML renderizado: só a rota corrente recebe o atributo).
  - **Header slim** no topo do conteúdo: hambúrguer (só mobile), **breadcrumb
    "Menu > {aba}"** (pedido do Fabio), e o cluster preservado — seletor de conta ativa
    (MT-1), `livewire:status-conexao` (com Reconectar/Desconectar), badge **Robo ON/OFF** e
    botão **Sair**. Mesmo markup de antes, só realocado. `flex-wrap` no header: em tela
    estreita ele quebra linha em vez de criar rolagem horizontal.
  - Banner "Robo desligado" e toasts: intactos, banner agora dentro da coluna de conteúdo.
  - O slot das páginas continua num contêiner `flex-1 min-h-0 overflow-hidden` idêntico ao
    antigo `<main>` — as páginas (Conversas, Kanban etc.) não percebem diferença.

`resources/css/app.css`: adicionado `@source` dos stubs do Flux — as utilitárias da sidebar
(`data-flux-sidebar-collapsed-desktop:w-14` etc.) entram no build do Tailwind de forma
determinística, sem depender de view compilada em `storage/`. Build do Vite rodado em
foreground, ok (CSS foi de ~215 KB pra ~260 KB por incluir todas as classes do Flux).

## Comportamento

- **Estado inicial: RETRAÍDA** (pedido explícito do Fabio). O Flux por padrão abre expandida
  e lê a preferência de `localStorage['flux-sidebar-collapsed-desktop']`; um script inline no
  `<head>` semeia essa chave com `true` **só se ela não existir**. Primeira visita = retraída;
  depois disso **a escolha do usuário persiste** (o próprio Flux grava a cada toggle —
  persistência entre navegações E entre sessões, no browser).
- **Desktop:** retraída = só ícones (w-14, tooltip no hover, clique no corpo dela também
  expande); expandida = ícones + rótulos (w-64). Botão de colapso no topo da sidebar.
  Coluna do conteúdo é `minmax(0, 1fr)` → sem rolagem horizontal da página em qualquer largura.
- **Mobile (<1024px):** sidebar vira painel **fixed por cima com backdrop**; abre pelo
  hambúrguer do header, fecha tocando fora (backdrop) e **ao navegar** (o `wire:navigate`
  recria o elemento, que nasce fechado no mobile). Conteúdo usa a largura toda — resolve a
  conversa espremida.
- **Dark mode:** componentes Flux já tematizados; cores da sidebar/header casam com o painel
  (white/zinc-900, bordas zinc-200/800).

## Testes

- Suite completa: **580 verdes** (era 577; +3 do novo `NavegacaoSidebarTest`): todas as 14
  abas do menu respondem **200 logado** (e redirect pro login deslogado), layout traz
  `ui-sidebar` colapsável, breadcrumb, Robo e Sair.
- Verificação server-side do HTML renderizado: ordem correta dos elementos pro grid do Flux
  (backdrop → `ui-sidebar` → `header` adjacentes), `collapsible="true"`/`sticky` presentes,
  `data-current` só no item ativo, `wire:navigate` nos itens, seed do localStorage no head.
- **Não** rodei browser de verdade (sem chromium/playwright no VPS; não instalei nada em
  produção) → visual fica no checklist abaixo.

## Checklist de teste manual (Fabio)

a. **Desktop largo:** sidebar retraída ao abrir (primeira visita); botão no topo dela
   expande/retrai; sem barra de rolagem horizontal.
b. **Desktop estreito (~1024–1280px):** idem; header pode quebrar em duas linhas, mas sem
   rolagem horizontal.
c. **Celular:** hambúrguer no header abre a sidebar por cima (fundo escurece); tocar num item
   navega e fecha; tocar fora fecha; abrir uma conversa → o chat usa a largura toda.
d. **Estado inicial retraído** (limpar o localStorage do site pra simular primeira visita:
   DevTools → Application → Local Storage → remover `flux-sidebar-collapsed-desktop`).
e. **Item ativo destacado** conforme a aba atual (inclusive navegando via wire:navigate).
f. **"Menu > Conversas"** (etc.) aparece no header em toda aba.
g. **Sair**, **Robo ON/OFF**, **conectado/Reconectar/Desconectar** (e o seletor de conta, se
   houver 2+) continuam no header e funcionando; badges de Kanban/Revisão aparecem na sidebar
   expandida e viram pontinho no ícone quando retraída.

## Observações

- Nenhuma rota nova, nenhum item renomeado, nenhuma página alterada.
- Se quiser a sidebar iniciando EXPANDIDA no futuro: apagar o script de seed no `<head>` do
  layout (o Flux volta ao padrão dele, expandida).
