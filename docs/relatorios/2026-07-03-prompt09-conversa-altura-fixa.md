# Prompt 09 — Conversa com altura fixa (dvh): header/input fixos, só mensagens rolam — 2026-07-03

**Status: ENTREGUE.** Baseline 581 verdes → final **581 verdes** (2192 assertions, sequencial),
`TenantIsolationTest` incluso. Mudança mínima: 1 utilitária CSS nova, 1 classe no `<body>`,
1 classe no scroller das mensagens. Zero lógica tocada.

## Diagnóstico (por que a página inteira rolava no celular)

A estrutura interna do chat **já era a correta** — `section` em flex-column com header
`shrink-0`, área de mensagens `flex-1 overflow-y-auto` (com `min-h-0` no pai) e composer
`shrink-0`; por isso o desktop sempre funcionou. O problema era a **altura do container raiz**:
o `<body>` usava `h-screen` (= `100vh`), e no mobile `100vh` inclui a área da barra de
endereço do navegador. Resultado: o body ficava mais alto que a área visível → a página
ganhava rolagem própria e o rodapé (caixa "Mensagem manual...") ficava escondido atrás da
barra do navegador até rolar até o fim. Exatamente a causa raiz que o prompt apontou.

## Correção

1. **`resources/css/app.css`** — utilitária nova:
   ```css
   @utility h-viewport {
       height: 100vh;                      /* fallback pra browsers sem dvh */
       @supports (height: 100dvh) { height: 100dvh; }
   }
   ```
   Detalhe: a primeira tentativa (`height:100vh; height:100dvh;` na mesma regra) teve o
   fallback **removido pelo minificador** do build; a forma com `@supports` sobrevive —
   conferido no CSS final: `.h-viewport{height:100vh}` + `@supports (height:100dvh){...}`.
2. **`app.blade.php`** — `<body>` trocou `h-screen` → `h-viewport`. O grid do Flux
   (sidebar/header/main) e o `overflow-hidden` da coluna de conteúdo já estavam certos; com a
   altura correta, a conta fecha: topo fixo (header slim + header da conversa), meio rolável
   (só as mensagens — única barra de rolagem), rodapé fixo (caixa de envio sempre visível).
3. **`conversas.blade.php`** — scroller das mensagens ganhou `overscroll-contain`: encostar
   no topo/fim das mensagens não encadeia o scroll pro documento (evita "puxar" a página no
   touch). Nada mais mudou na view.

Aplicado no layout (vale pra todas as abas, que já tinham scroll interno próprio) — não
mobile-only, porque **no desktop `100dvh` == `100vh`** (não há barra dinâmica): o desktop
renderiza pixel-a-pixel igual. Teclado no mobile: `dvh` acompanha a viewport dinâmica no
Android; no iOS o teclado é overlay (limitação do Safari, sem mudança de comportamento).

## Confirmação de que o desktop não regrediu

- Em desktop, `dvh` e `vh` têm o mesmo valor — a troca é literalmente sem efeito visual.
- Nenhuma classe/estrutura das duas colunas foi alterada (o diff da view de conversas é só o
  `overscroll-contain` no scroller, que não muda scroll com mouse/roda).
- O auto-scroll pro fim (Alpine `scrollToBottom` + MutationObserver) aponta pro mesmo
  `x-ref="scroller"` — intocado.
- Suite completa verde (581), incluindo os testes de Conversas (look/input/anexos) e
  `TenantIsolationTest`.

## Testes

- Suite: **581 verdes** (mesma contagem — não há lógica nova pra testar; layout puro).
- Vite rebuildado em foreground; conferido no CSS final o `.h-viewport` com fallback e o
  `overscroll-behavior:contain`.
- Sem browser no VPS (como nos prompts 07/08) → visual no checklist abaixo.

## Checklist de teste manual (Fabio)

a. **Celular:** abrir uma conversa → header da conversa em cima E caixa de envio embaixo
   visíveis de imediato, sem rolar a página; só o meio (mensagens) rola.
b. **Celular:** mensagem nova (recebida ou enviada) aparece no fim sem rolar manualmente;
   digitar mensagem de várias linhas → textarea cresce pra cima sem quebrar o layout nem
   esconder mensagens além do necessário.
c. **Celular:** teclado aberto → caixa de envio continua acessível (Android deve acompanhar;
   iOS pode sobrepor — limitação do Safari, relatar se incomodar).
d. **Desktop largo e estreito (≥1024px):** confirmar que NADA mudou — duas colunas, seleção,
   envio, scroll das mensagens, header/input já fixos como antes.
e. Nenhuma barra de rolagem da página inteira em nenhuma aba/tamanho (a única vertical
   visível na conversa é a das mensagens); zero rolagem horizontal.
f. Dark mode consistente (nada de cor mudou).
