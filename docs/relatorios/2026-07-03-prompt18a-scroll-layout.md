# Prompt 18A — Scroll das páginas de conteúdo (Perfil, Logs, Painel) — 2026-07-03

**Status: ENTREGUE.** Baseline 606 verdes → final **606 verdes** (nenhuma lógica mudou; troca de
uma classe CSS no layout). `TenantIsolationTest` incluso na suíte verde.

## Causa confirmada
`resources/views/components/layouts/app.blade.php:139` — o wrapper de conteúdo compartilhado era
`<div class="min-h-0 flex-1 overflow-hidden">{{ $slot }}</div>`. Esse `overflow-hidden` (introduzido
no refino da Conversas, prompt 09) prendia TODAS as páginas: conteúdo além da altura da tela ficava
cortado e inacessível.

Por que Conversas e Contatos não sofriam: ambas **se auto-contêm**:
- `conversas.blade.php` raiz `flex h-full` (scroll interno só nas mensagens);
- `contatos.blade.php` raiz `h-full overflow-y-auto` (scroll próprio).

Perfil (`mx-auto max-w-3xl ... p-6`), Logs (`mx-auto max-w-5xl ... p-6`) e Painel não têm scroll
próprio — dependiam do wrapper, e o `overflow-hidden` os cortava (ex.: botão "Ativar 2FA" no fim do
Perfil, mensagens antigas nos Logs).

## Técnica escolhida (mínima) e por quê
Troquei **só** o wrapper de conteúdo: `overflow-hidden` → **`overflow-y-auto`**. O `<main>`
(`:128`) continua `overflow-hidden` (caixa fixa `100dvh` do grid Flux — mantém a contenção de
altura e zero rolagem horizontal de página).

- **Perfil/Logs/Painel:** conteúdo maior que a altura → o wrapper rola. Resolvido.
- **Conversas:** raiz `h-full` preenche exatamente a altura do wrapper; seus filhos somam `h-full`
  (header shrink-0 + mensagens flex-1 com scroll interno + input shrink-0) → o wrapper não tem
  overflow → **não gera scroll duplo**; só as mensagens rolam. `100dvh`/header/input fixos
  preservados no desktop e no mobile (uma coluna por vez) — a estrutura da Conversas não foi tocada.
- **Contatos:** `h-full overflow-y-auto` preenche a altura e rola por dentro; wrapper não duplica.

Escolhi escopar pelo wrapper (em vez de mexer em cada página) porque a contenção de altura vive no
layout compartilhado — um ponto único, sem tocar nas telas nem na Conversas. Alternativa (pôr cada
página no fluxo natural) exigiria mexer em N blades e arriscaria a auto-contenção da Conversas.

Sem migration. Sem rebuild de assets (a classe `overflow-y-auto` já está no CSS — Contatos/emoji
já a usam). Suíte 606 verdes.

## Checklist de validação manual (Fabio)
- [ ] **Perfil** rola até o fim; botão "Ativar 2FA" acessível.
- [ ] **Logs** rola a lista inteira.
- [ ] **Painel** rola se o conteúdo passar da tela.
- [ ] **Conversas desktop:** duas colunas, header/input fixos, só as mensagens rolam (intacto).
- [ ] **Conversas mobile:** uma coluna por vez, `100dvh`, só as mensagens rolam (intacto).
- [ ] **Sidebar** responsiva colapsável intacta.
- [ ] Sem rolagem horizontal de página em nenhuma tela.
