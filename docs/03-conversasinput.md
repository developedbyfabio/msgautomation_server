# 03 — Conversas: Enter envia, Ctrl+Enter quebra linha, textarea que cresce, emojis

Melhorias de usabilidade na caixa de mensagem da aba **Conversas**. Baixo risco, mas mexe em UI viva —
cuidado pra nao quebrar o envio manual que ja funciona.

Releia o `00-LEIA-PRIMEIRO.md`. Baseline: git limpo, ler docs/09 e docs/10, `php artisan test` verde.

## Problemas atuais (relatados pelo Fabio)
1. Ao apertar **Enter** na caixa de mensagem, ele **pula linha** — deveria **enviar** a mensagem.
2. Nao ha atalho pra quebrar linha de proposito.
3. A caixa nao cresce: se o texto tem varias linhas, nao da pra ver tudo.
4. Emojis nao sao reconhecidos/exibidos direito.

## Requisitos
- **Enter = enviar** a mensagem (o comportamento padrao de WhatsApp/chat).
- **Ctrl+Enter (e Shift+Enter) = quebrar linha** dentro da mensagem, sem enviar. Suportar os dois
  (Shift+Enter e o mais comum; Ctrl+Enter o Fabio pediu explicitamente — aceitar ambos).
- **Textarea auto-crescente**: conforme o texto ganha linhas, a caixa aumenta de altura pra mostrar
  tudo, ate um limite razoavel (ex.: ~6-8 linhas), depois vira scroll interno. Ao enviar/limpar,
  volta a altura minima. Isso e comportamento de front (Alpine), sem recarregar a pagina.
- **Emojis**: garantir que a caixa aceita, exibe e **envia** emojis corretamente (encoding UTF-8/utf8mb4
  ponta a ponta — input, persistencia no banco, e no envio pelo canal). Se o banco/coluna nao estiver
  em utf8mb4, ajustar de forma **aditiva/segura** (migration que altera charset da coluna de mensagem
  pra utf8mb4 sem perder dados; se for arriscado no volume atual, documentar e propor, nao forcar).
  Idealmente um seletor de emoji simples tambem (opcional; o minimo e aceitar/exibir/enviar emoji
  digitado ou colado).

## Cuidados (nao quebrar)
- O envio manual hoje "envia de verdade (respeita tetos, ignora kill switch)" — manter esse
  comportamento intacto. So muda **como** dispara (tecla), nao **o que** faz.
- Nao interferir no pipeline reativo nem nos freios.
- Funcionar nos dois canais (Evolution e Cloud) sem tratamento especial na UI.
- Acessibilidade: um hint discreto tipo "Enter envia, Ctrl+Enter quebra linha" perto da caixa ajuda.

## Testes
- Onde houver logica testavel (ex.: normalizacao/persistencia de mensagem com emoji), testar que
  emoji sobrevive ida e volta (salva e le igual).
- Se a mudanca for majoritariamente front (Alpine), documentar o teste manual no relatorio (enter
  envia, ctrl/shift+enter quebra, caixa cresce, emoji aparece) e garantir que a suite existente
  continua verde (nada de regressao no envio).
- Suite completa verde.

## Ao terminar
Verde: commita ("feat: conversas — enter envia / ctrl+enter quebra linha / textarea auto-crescente / emojis"),
push sem force, relatorio em docs/relatorios/ (incluindo o checklist de teste manual do front). Passa pro `04`.
Quebrou: PARA no ultimo verde, relata. Nao segue.
