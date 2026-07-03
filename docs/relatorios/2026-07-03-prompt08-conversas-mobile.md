# Prompt 08 — Conversas no mobile: uma coluna por vez — 2026-07-03

**Status: ENTREGUE.** Baseline 580 verdes → final **581 verdes** (2192 assertions, sequencial),
`TenantIsolationTest` incluso. Mudança cirúrgica: só a view de Conversas + um método de
apresentação no componente. Nenhuma outra aba, nenhuma lógica de negócio/envio tocada.

## Confirmação: DESKTOP NÃO MUDOU

A visibilidade é 100% classe responsiva no breakpoint **`lg` (1024px)** — o mesmo da sidebar
do prompt 07. Verifiquei o HTML renderizado nos dois estados (com e sem conversa selecionada):
em `lg+` as classes efetivas dos dois painéis são **idênticas às originais** em qualquer estado:

- Lista: `flex w-80 shrink-0 flex-col` (via `lg:flex lg:w-80 lg:shrink-0`) — sempre visível.
- Thread: `flex min-w-0 flex-1 flex-col` (via `lg:flex`) — sempre visível, com o placeholder
  "Selecione uma conversa." quando nada aberto, como hoje.
- O botão voltar é `lg:hidden` → não existe no desktop. Selecionar conversa NÃO esconde a lista.

## O que mudou (mobile, < 1024px)

`resources/views/livewire/conversas.blade.php`:
- **Sem conversa selecionada:** lista em tela cheia (`flex w-full`); painel da thread `hidden`.
- **Com conversa selecionada:** lista `hidden`; thread em tela cheia (largura toda, sem coluna
  espremida/texto vertical).
- **Botão voltar** (seta ←) no cabeçalho da thread, só mobile, chama `voltarParaLista`.
- O badge textual "auto: segue a politica (default)" ganhou `max-lg:hidden` — não cabia em
  360px e criaria rolagem horizontal; o estado continua visível na cor dos botões
  Aprovar/Silenciar (e no painel do contato). Desktop: badge igual a hoje.

`app/Livewire/Conversas.php`:
- Método novo `voltarParaLista()`: só limpa `selectedJid`/`sendStatus`/`showContactPanel`
  (estado de apresentação, espelho do `select()`). Zero lógica de negócio.

A alternância usa o estado que **já existia** (`$selectedJid`) — nada de estado paralelo
Alpine; o `wire:poll.5s` e o morph do Livewire re-renderizam as classes naturalmente. O link
do Kanban (`/conversas?jid=...`) continua funcionando: no mobile já abre direto na thread,
com o voltar levando pra lista.

## Testes

- Suite completa: **581 verdes** (era 580). Novo caso em `ConversasLookTest`:
  `test_voltar_para_lista_limpa_a_selecao` — select → thread com botão voltar →
  `voltarParaLista` → seleção limpa → placeholder de volta.
- Vite rebuildado em foreground; conferido que `lg:w-80`, `lg:shrink-0`, `max-lg:hidden`,
  `lg:hidden`, `lg:flex` estão no CSS final.
- Sem browser no VPS (mesma situação do prompt 07) → visual no checklist abaixo.

## Checklist de teste manual (Fabio)

a. **Desktop largo:** duas colunas lado a lado; selecionar conversa abre na direita; **sem**
   botão voltar; lista nunca some. Confirmar que está IGUAL a ontem.
b. **Desktop estreito (≥1024px):** idem, sem rolagem horizontal.
c. **Celular:** abre na **lista em tela cheia**; tocar numa conversa → **conversa em tela
   cheia** (texto normal, sem coluna espremida); seta ← volta pra lista.
d. **Celular:** enviar mensagem manual, anexar, emoji, scroll do histórico e botão "ir pra
   última" funcionando na thread em tela cheia; busca funcionando na lista.
e. **Kanban → conversa** no celular: abre direto na thread; voltar leva pra lista.
f. Dark mode ok nos dois modos; zero rolagem horizontal no celular.
g. Cabeçalho slim (Menu > Conversas, Robô, Sair) intacto — não foi tocado neste prompt.
