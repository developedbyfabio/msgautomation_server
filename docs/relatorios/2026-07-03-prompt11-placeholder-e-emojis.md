# Prompt 11 — Composer: placeholder "Digitar..." + seletor de emojis completo (abas + busca)

**Status: ENTREGUE.** Baseline 581 verdes → final **581 verdes** (2192 assertions,
`TenantIsolationTest` incluso). Só o composer da conversa foi tocado (placeholder + picker).
Nada de mídia, envio, pipeline reativo ou lógica de negócio.

> Nota de continuidade: a fatia começou noutra sessão (modelo Fable 5) e foi interrompida no
> meio da edição do blade — o `x-data` novo já estava aplicado, mas o popup ainda usava a
> grade fixa antiga e o placeholder não tinha mudado. Retomada aqui: conferido o estado,
> terminado o popup com abas, feito o placeholder, validado e commitado.

## Parte A — Placeholder "Digitar..."

`resources/views/livewire/conversas.blade.php`: a caixa de texto agora usa
`placeholder="Digitar..."` (era "Mensagem manual (envia de verdade)...").

O aviso de segurança **não sumiu** — migrou pra um `flux:tooltip` no botão **Enviar**:
"Envio manual: envia de verdade (respeita os tetos, mas ignora o kill switch — manda mesmo com
o robo desligado)." Assim o placeholder fica curto como o Fabio pediu e o lembrete de que o
manual dispara pra valer continua acessível, sem poluir a caixa.

## Parte B — Seletor de emojis completo

**Caminho escolhido: solução própria (Alpine + Unicode), sem lib.** Como o prompt pediu, em
dúvida sobre lib prefere-se o previsível; um emoji-picker de terceiros traria peso e risco de
build. A escolha é 100% Unicode + Alpine + Tailwind, self-contained.

### Dados — `resources/js/app.js`
`window.emojiCategorias`: **9 categorias, 1043 emojis** (Sorrisos e emoção, Pessoas e corpo,
Animais e natureza, Comida e bebida, Viagem e lugares, Atividades, Objetos, Símbolos,
Bandeiras). Cada item é `"<emoji> <palavras-chave pt-BR>"`.
- **Unicode puro, sem sprite/imagem**: cada aparelho renderiza no estilo nativo (Apple no iOS,
  Noto no Android). Seleção conservadora (até Unicode 12/13, já universais) pra não virar
  quadradinho em celular antigo.
- Fica em `window.*` (fora do payload do Livewire), então o `wire:poll.5s` da conversa não
  recarrega o dataset a cada ciclo. Bundle JS: 22.9 kB (10.6 kB gzip) — leve.

### UI — popup com abas + busca (no composer)
- **Abas por categoria** no rodapé do popup (ícone representativo de cada uma); clicar troca a
  categoria e limpa a busca; aba ativa destacada em verde.
- **Campo de busca** no topo: filtra por nome/palavra-chave em **todas** as categorias, com
  remoção de acento nos dois lados (digitar "cora" ou "coração" acha ❤️ 😍 💑; "kkk" acha 🤣 😂;
  "pizza" acha 🍕; "brasil" acha 🇧🇷). Limita a 96 resultados pra não travar o render.
- **Grade rolável** (única barra de rolagem: vertical, `overscroll-contain`), 8 colunas.
- **Inserção no cursor**: reaproveita o `inserir()` do prompt 03 (respeita seleção/posição,
  devolve o foco à caixa, sincroniza a auto-altura da textarea). **Não dispara envio.**
- Fica **aberto ao escolher** (pra pegar vários emojis) e **fecha ao clicar fora**
  (`@click.outside`). Abre/fecha pelo mesmo botão de rosto sorridente já existente.
- **Mobile**: largura travada em `w-80 max-w-[calc(100vw-1.5rem)]` — cabe na tela sem rolagem
  horizontal; a grade rola internamente. Dark mode consistente (zinc-800/700 + destaque
  emerald-900).

## Cuidados preservados

Enter envia, Ctrl/Shift+Enter quebra linha, textarea auto-crescente, anexos (clipe), layout de
altura fixa do prompt 09 — nada mudou nesses caminhos (o diff é só o `x-data`, o popup e o
placeholder/tooltip). O `@keydown.enter.prevent @keydown.stop` no campo de busca evita que
Enter dentro da busca dispare o envio da mensagem.

## Testes

- Suite completa: **581 verdes** (sem regressão). `ConversasInputTest` (persistência de emoji +
  quebra de linha no envio manual, do prompt 03) segue passando — como os emojis são Unicode e
  o banco é utf8mb4, persistência/envio já funcionavam.
- Validação da busca em Node (mesma `norm()` do blade) e render server-side confirmando:
  campo de busca, abas (`cat.icone`), estado Alpine, `placeholder="Digitar..."`, tooltip de
  segurança presentes, e a grade fixa antiga removida.
- Vite rebuildado em foreground, sem erro.

## Checklist de teste manual (Fabio)

a. **Desktop e mobile:** a caixa mostra "Digitar...". Passar o mouse no botão Enviar mostra o
   aviso "envia de verdade / ignora o kill switch".
b. Botão de emoji abre o popup com **9 abas** de categoria embaixo.
c. Clicar numa aba troca os emojis mostrados; a aba ativa fica destacada.
d. Clicar num emoji **insere na posição do cursor** e **não envia**; dá pra escolher vários
   (popup só fecha ao clicar fora).
e. **Busca:** digitar "cora", "pizza", "brasil", "kkk" filtra corretamente (com e sem acento).
f. **Mobile:** o popup cabe na tela, sem rolagem horizontal; a grade rola internamente.
g. Enter envia, Ctrl/Shift+Enter quebra linha, textarea cresce, anexar funciona — tudo intacto.
h. Dark mode consistente.

## Melhorias futuras (opcional)
- "Recentes/favoritos" (persistir os últimos usados em localStorage).
- Mais emojis por categoria, se o uso pedir (o formato do dataset já suporta crescer).
