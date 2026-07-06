# Fatia 20 — Kanban: drag-and-drop + proteção do movimento manual + arquivar por inatividade — 2026-07-06

Git no início: HEAD `e382467` (fatia 19), working tree limpo exceto os dois relatórios untracked
pré-existentes (fora do commit). Baseline: **862 verdes / 3430 assertions**.

---

## Mapeamento (lido antes de escrever)

- **Action de mover reusada:** `Kanban::moveCard(int $cardId, int $columnId)` — posse validada
  server-side (card e coluna buscados pelo board da CONTA), no-op na mesma coluna, transição
  `cause='manual'`. Os 3 pontinhos já a chamam; o drag passa a chamá-la também. **Ordem
  intra-coluna NÃO é persistida hoje** (board ordena por `last_interaction_at` desc) — **não
  inventada** (registrado): o drop define só a coluna.
- **Transições automáticas que passaram a checar o pin:** (1) `BoardEngine::moveOrCreate` — o
  caminho de TODAS as regras (`resposta_enviada`, `envio_manual`, `mensagem_recebida`...);
  (2) `BoardEngine::moveToColumnSlug` — os moves determinísticos (handoff da Fatia 5/11,
  `sem_resposta` das Fatias 11/16). Dois pontos, cobertura total.
- **Ponto do inbound (release + desarquivar):** `ProcessIncomingWhatsappMessage::handle`, logo
  após `popularContato` (que já exclui fromMe/grupo) e **ANTES** do `event(IncomingMessageStored)`
  — ordem segura até em fila sync: a própria mensagem que libera já aciona a transição normal.
- **Última atividade:** `cards.last_interaction_at` (o `touch()` do engine mantém).
- **X dias:** input no diálogo (default **30**, clamp 1–365) — escolhido por ser o mais simples
  (zero coluna de setting nova; o dono ajusta por operação, caso a caso). Registrado.

## Migration (aditiva) — aplicada em produção

`2026_07_06_000004`: `cards.pinned_until_reply` (bool default false) + `cards.archived_at`
(timestamp nullable). Backfill trivial pelos defaults. **Confirmado por leitura em produção:**
41 cards, 0 pinned, 0 arquivados (estado esperado pós-migração).

## A — Drag-and-drop (SortableJS 1.15.7, npm, lazy)

- `sortablejs` via npm; **dynamic import** no `initKanbanDnd` do `app.js` — o chunk
  (`sortable.esm`, 36,5 kB / gzip 12,6 kB) só baixa com o Kanban aberto. **Bundle principal:
  25,53 → 26,34 kB (+0,8 kB, só o init).** Zero CDN.
- Wiring: `#kanban-board` + `data-kanban-col` (container de cada coluna) + `data-card-id`;
  `Sortable` com `group` compartilhado; **estratégia anti-conflito com o morph registrada:** no
  `onEnd` o DOM volta ao estado pré-drag e o `moveCard` re-renderiza — o servidor é a verdade
  única (sucesso = card na coluna nova; falha/posse = permanece; "reverter" é o próprio morph).
- **3 pontinhos preservados** (acessibilidade — drag não é o único meio); cursor grab/grabbing.

## B — Proteção do movimento manual (pin até a próxima mensagem)

- **Set:** `moveCard` (humano — drag OU 3 pontinhos, mesma action) grava
  `pinned_until_reply=true` (toast explica). Transições automáticas **não** setam (provado).
- **Honrado:** `moveOrCreate` retorna o card intacto quando pinned (nenhuma transição);
  `moveToColumnSlug` só faz `touch` e retorna. Best-effort/isolado como todo o Kanban.
- **Release:** bloco try/catch no inbound (acima) zera `pinned_until_reply` e `archived_at` dos
  cards do contato — **antes** do evento. Provado o par crítico: (a) card arrastado pra Resolvido
  + robô responde via aprovação (`Sender::send('aprovacao')` → `resposta_enviada`) → **não** volta;
  o move determinístico (`sem_resposta`) também respeita; (b) contato escreve → pin solta E a
  cadeia normal age (reabertura resolvido→novo provada por transição + estado final `aguardando`
  pelo sem-resposta da 11 — o fluxo reassume de ponta a ponta).
- **Indicador visual:** cadeado âmbar ao lado do nome no card + tooltip "movido manualmente — o
  robô não altera até a próxima mensagem do contato" (a cor nunca é o único indicador).

## C — Arquivar parados (reversível, escopado)

- Botão por coluna (ícone archive, `aria-label`) → modal com **input de dias** (`wire:model.live`
  — contagem/lista atualizam ao vivo), mostrando **quantos e quais** cards serão arquivados
  (nome + "parado há X"); zero elegíveis = aviso e botão desabilitado (no-op).
- **Seleção estrita:** `board da conta` + `column_id` + `archived_at IS NULL` +
  `last_interaction_at < now()-X` (`last_interaction_at` null = **não** elegível — só arquiva
  inatividade provada; registrado). **Nunca** a coluna inteira, **nunca** cards ativos.
- **Nunca físico:** `UPDATE archived_at = now()` — provado por teste que a linha continua
  existindo. O board filtra `whereNull('archived_at')` (query única do render).
- **Auto-restauração:** o mesmo bloco do inbound zera `archived_at` — contato escreveu, card
  volta ao board no fluxo normal (provado). O engine opera no card mesmo arquivado (a
  visibilidade é só do board) — semântica mínima consistente, registrada.
- **Visão "arquivados" (opcional): PULADA e registrada** — a auto-restauração garante que nada
  some pra sempre; uma listagem/restauração manual é fatia futura se o dono pedir.

## Ajustes deliberados em testes

**Zero.** As transições das Fatias 11/16 para cards não fixados seguem verdes sem nenhuma
alteração (nada seta pin nos cenários existentes).

## Testes (`KanbanDndPinArchiveTest`, 9 casos)

- Move humano persiste via a action existente E fixa o card (transição `manual`); posse: card de
  outra conta é no-op.
- **Par crítico:** fixado não é movido nem pela regra (`resposta_enviada` via aprovação) nem pelo
  determinístico (`sem_resposta` — zero transição); release no inbound com o fluxo reassumindo em
  cadeia (reabertura provada + final `aguardando`); transição automática NÃO seta pin (e a
  regressão do automático segue: card não fixado vai pra `em_atendimento` no envio).
- **Arquivar:** só o parado há 40 dias é arquivado (o recente permanece — coluna não esvaziada);
  contagem/lista no diálogo; **nunca físico** (linha existe com `archived_at`); arquivado some do
  render; **X configurável** (10 dias parado: no-op no default 30, elegível com X=5);
  **desarquiva no inbound**; posse (coluna de outra conta: modal nem abre, card da B intacto).

## Contagem de testes

| | testes | assertions |
|---|---|---|
| Antes | 862 | 3430 |
| Depois | **871** | **3457** |

Suíte inteira **sequencial**, tudo verde.

## Confirmações explícitas

- **Nenhuma decisão de resposta alterada** — o diff do pipeline é só o bloco best-effort de
  release/desarquivar (try/catch isolado, padrão K); nenhuma action/rota de escrita de movimento
  nova (drag reusa `moveCard`).
- Migrations aditivas aplicadas em produção e confirmadas por leitura; SortableJS npm + lazy
  (sem CDN, bundle principal +0,8 kB); isolamento por conta em drag, pin e arquivar (testado).
- **`queue:restart` executado após o commit (funcional: transições e release rodam em jobs)** —
  worker novo confirmado (pid/horário na resposta).

## Commit
Local, sem push (Fabio empurra). Hash reportado na resposta.
