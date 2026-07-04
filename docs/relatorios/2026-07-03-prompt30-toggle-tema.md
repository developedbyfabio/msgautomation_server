# Prompt 30 — Botão de alternar tema (claro/escuro) no header — 2026-07-03

**Status: ENTREGUE.** Baseline 666 → **666 verdes** (só UI; +2 assertions no teste de layout),
`TenantIsolationTest` 28. Sem migration, sem backend — só o controle no header.

## Passo 1 — Como o dark mode funciona hoje
- **Tailwind `dark` class no `<html>`**: `resources/css/app.css:4` `@custom-variant dark (&:where(.dark, .dark *))`
  — os estilos `dark:` das telas dependem da classe `dark` no `<html>`.
- **Mecanismo de appearance do Flux (free), já presente:** o layout tem `@fluxAppearance` no `<head>`
  (`app.blade.php:48`) — script inline que lê `localStorage['flux.appearance']` (default `system` →
  `prefers-color-scheme`) e aplica/remove a classe `dark` **antes do render** (anti-flash). O
  `@fluxScripts` (`:183`) expõe o magic Alpine **`$flux.appearance`** (getter =
  `localStorage.getItem('flux.appearance') || 'system'`; setter aplica a classe **e persiste**).
- **Persistência já existia** (via Flux, chave `flux.appearance`) — faltava só o **controle** pra
  alternar. Reusei o Flux (não reimplementei estilos nem persistência nem anti-flash).
- Botão "Sair": form de logout no header (`app.blade.php:123`) — o toggle ficou ao lado.

## Passo 2 — Toggle
`resources/views/components/layouts/app.blade.php` (header, antes do "Sair"): botão com ícone
sol/lua (Flux `sun`/`moon`), Alpine:
```blade
x-data="{ dark: document.documentElement.classList.contains('dark') }"
@click="dark = ! dark; $flux.appearance = dark ? 'dark' : 'light'"
```
- `dark` é reativo (Alpine), iniciado da classe atual (que o `@fluxAppearance` já aplicou anti-flash).
- Clique: flipa `dark` e seta `$flux.appearance` (`dark`/`light`) — o setter do Flux **aplica a classe
  e persiste** em `localStorage['flux.appearance']`.
- Ícone: `sun` some/aparece por `x-show="dark"`, `moon` por `x-show="! dark"`, com `x-cloak` (regra
  `[x-cloak]{display:none}` do app.css, prompt 14) pra não piscar os dois antes do Alpine iniciar.

**Anti-flash:** garantido pelo `@fluxAppearance` (script no head do Flux) — nenhum script novo foi
necessário; é o mecanismo que o prompt pediu, já presente. Default `system` respeita o
`prefers-color-scheme` quando não há escolha salva.

## GATE
- Só UI: layout + 1 botão. **Não** toca reativo, isolamento, canal, backend. Sem migration.
- Estilos light/dark das telas inalterados (o toggle só liga/desliga a classe `dark`).
- Preferência **por navegador** (`localStorage`), não no banco — não é config multi-tenant.
- `TenantIsolationTest` 28 verde; suíte 666 verde.

## Teste
`NavegacaoSidebarTest::test_layout_traz_sidebar_e_breadcrumb_de_contexto`: passou a afirmar o toggle
presente no header (`aria-label "Alternar tema claro/escuro"` + `$flux.appearance`). A alternância/
persistência/anti-flash são client-side (Alpine/Flux) — checklist manual abaixo.

## Checklist manual (Fabio)
- [ ] Clicar no ícone sol/lua no header alterna claro ↔ escuro na hora.
- [ ] A escolha **persiste** após reload e ao navegar entre telas.
- [ ] **Sem flash** do tema errado no carregamento (o head aplica antes de pintar).
- [ ] Ícone reflete o estado (sol no escuro / lua no claro).
- [ ] Funciona no desktop e no mobile (o botão está no header, presente em todas as telas).
- [ ] Sem escolha salva, respeita o tema do sistema (`prefers-color-scheme`).
