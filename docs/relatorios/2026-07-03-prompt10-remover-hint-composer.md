# Prompt 10 — Remover hint abaixo do composer de conversas — 2026-07-03

**Status: ENTREGUE.** Baseline 581 verdes → final **581 verdes** (2192 assertions,
`TenantIsolationTest` incluso). Remoção de texto de UI; zero comportamento tocado.

## O que foi feito

- Removida a linha `<p>` do hint ("Enter envia · Ctrl+Enter ou Shift+Enter quebra linha ·
  Envio manual envia de verdade...") em `resources/views/livewire/conversas.blade.php`.
  É um elemento único e responsivo — some no desktop E no mobile de uma vez.
- Sem gap sobrando: o `<p>` era o último filho do rodapé (que tem `p-3` próprio); removido,
  o composer encosta limpo no padding. Nenhuma classe de layout mudou (estrutura flex do
  rodapé fixo do prompt 09 intacta) — por isso nem rebuild do Vite foi necessário (nenhuma
  classe nova/removida do conjunto usado).

## Ponderação registrada: escolhi a opção (b), na forma mais barata

O trecho "envia de verdade, ignora o kill switch" é aviso de segurança real. Preservei a
essência **sem reintroduzir poluição**: o placeholder da caixa mudou de
`Mensagem manual...` → **`Mensagem manual (envia de verdade)...`** — uma palavra a mais que
só aparece quando a caixa está vazia, custo visual zero. Todo o resto do hint (dicas de
Enter/Ctrl+Enter) foi removido sem substituto, como pedido. Se o Fabio preferir o placeholder
antigo puro, é reverter uma palavra.

## Comportamento intocado (conferido por diff)

Enter envia / Ctrl+Enter e Shift+Enter quebram linha / textarea auto-crescente / anexos /
emoji: nenhuma linha de Alpine ou Livewire mudou — o diff é a remoção do `<p>`, o texto do
placeholder e um comentário Blade.

## Checklist de teste manual (Fabio)

a. **Desktop:** hint sumiu; enviar, quebrar linha (Ctrl/Shift+Enter), anexar e emoji ok.
b. **Mobile:** hint sumiu; composer funciona igual.
c. Sem buraco/gap onde o texto estava (rodapé limpo).
d. Layout de altura fixa do 09 intacto (header/input fixos, só mensagens rolam).
