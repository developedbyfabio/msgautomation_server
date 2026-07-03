# Diagnóstico — reações viram mensagens (SÓ LEITURA) — 2026-07-03

## Estado do git no início (transcrição literal)
`git status`: **On branch master / up to date / nothing to commit, working tree clean.**
`git diff --stat`: **vazio.** Último commit: `2b863d2`. Nenhuma alteração não-commitada — nada
a decidir. (Este relatório é o único arquivo novo.)

---

## VEREDITO (4 linhas)
1. **Reação entra no backend como linha de mensagem recebida: SIM.** 173 linhas `type=reactionMessage`
   em `incoming_messages` (0 `type=reaction` do Cloud — o número oficial ainda não recebeu reação);
   nos últimos 50 registros, 3 são reações.
2. **Toca o pipeline reativo:** métrica do painel = **SIM** (infla recebidas/grupos); Kanban/evento =
   **SIM só em 1:1** (grupo é barrado); IA (ClassifyWithAi) = **SIM só em 1:1 e só se a IA estiver
   ligada** (OFF por padrão → dormente); disparo de resposta por **regra = NÃO** (texto nulo nunca casa);
   unmatched = **NÃO** (guard de texto vazio).
3. **Natureza do bug: AMBOS.** O backend ingere a reação como mensagem de primeira classe (conta
   métrica, toca pipeline 1:1) **e** o front a renderiza como bolha própria "reagiu".
4. **Onde corrigir (recomendação, NÃO aplicado):** interceptar na **ingestão** — o ideal é não gravar
   reação como `IncomingMessage` de thread (ou gravar num modelo à parte / anexar ao alvo via
   `reactionMessage.key.id` / `reaction.message_id`). Pontos: `EvolutionProvider::normalizeIncoming`
   (`app/Channels/Evolution/EvolutionProvider.php:252`), `CloudApiProvider::normalizeIncoming`
   (`app/Channels/CloudApi/CloudApiProvider.php:142`) ou `ProcessIncomingWhatsappMessage::persistir`
   (`app/Jobs/ProcessIncomingWhatsappMessage.php:234`). Complemento de exibição:
   `Conversas::thread()` (`app/Livewire/Conversas.php:494`) + blade. Corrigir só o front deixaria as
   métricas infladas e o pipeline 1:1 ainda avaliando reações.

---

## Bloco 1 — Como a reação chega em cada canal

**1.1 Evolution** — ponto de entrada: `EvolutionWebhookController` → job `ProcessIncomingWhatsappMessage`
→ `EvolutionProvider::normalizeIncoming`. A reação chega como **`messages.upsert`** com
`data.message.reactionMessage` (contém `key.id` = mensagem-alvo e `text` = emoji). O adaptador é
**catch-all** e não descarta por tipo (`app/Channels/Evolution/EvolutionProvider.php:221-224`):
```
 * Catch-all: SEMPRE resolve um tipo e SEMPRE devolve o DTO pra qualquer
 * messageType — nada e descartado por tipo, so por falta de key.id/jid.
```
`$type` vem de `messageType` (`:252`) → `reactionMessage`. Payload real (ofuscado, id 3852):
```
type=reactionMessage  remote_jid=****************16@g.us  (grupo)
reactionMessage.key.id = "3EB036F54948B6DD993625"   ← mensagem-alvo
reactionMessage.text   = "🙏"                          ← emoji
```

**1.2 Cloud API** — ponto de entrada: `ChannelWebhookController` → `CloudApiProvider::normalizeIncoming`
(`app/Channels/CloudApi/CloudApiProvider.php:113`). Pega `messages.0` (`:131`) e usa `msg['type']`
direto (`:142`) — **sem filtrar `reaction`**. Uma reação chegaria como
`entry[].changes[].value.messages[]` com `type == "reaction"` e `reaction = { message_id, emoji }`,
e seria normalizada como `type='reaction'`. Não há payload real (0 reações Cloud no banco — número
oficial de teste sem histórico).

## Bloco 2 — O que o código faz com o payload

**2.1** Model/tabela: **`App\Models\IncomingMessage` / `incoming_messages`**. Caminho:
`normalizeIncoming` → `ProcessIncomingWhatsappMessage::handle` → `persistir` → `IncomingMessage::create`.

**2.2 Checa o tipo antes de criar a linha? NÃO.** `persistir`
(`app/Jobs/ProcessIncomingWhatsappMessage.php:234-254`) grava qualquer DTO como mensagem, sem olhar
o tipo — só trata idempotência (unique instance+id):
```php
return IncomingMessage::create([
    ...
    'type' => $data->type,          // 'reactionMessage' entra igual
    'text' => $data->text,          // reação: null
    'raw_payload' => $data->raw,
    ...
]);
```
Antes disso, `normalizeIncoming` (Bloco 1) também não barra reação. Logo: reação **vira linha**.

**2.3 Valores gravados:** `type='reactionMessage'` (Cloud seria `'reaction'`), `text=null` (o
`extrairTexto` da Evolution não extrai `reactionMessage.text`; o do Cloud
`app/Channels/CloudApi/CloudApiProvider.php` também não tem ramo `reaction` → null), `raw_payload`
com o nó completo. **A referência à mensagem-alvo existe e NÃO é usada:** `reactionMessage.key.id`
(Evolution) / `reaction.message_id` (Cloud), além do emoji em `reactionMessage.text` /
`reaction.emoji`. Não há coluna dedicada a reação; a coluna `type` já distingue, mas nada a trata.

## Bloco 3 — Evidência no banco (read-only)

Query via tinker (SELECT apenas):
- `type=reactionMessage`: **173** linhas. `type=reaction` (Cloud): **0**.
- Últimos 50 registros: **3** são reações. → **3.2: SIM, reações estão gravadas como linhas próprias.**

Exemplos (ofuscados):
```
id=3888 type=reactionMessage text=null grupo=sim  key.id(alvo)="3EB082CAC38C3084A6E0E4"  emoji=null
id=3865 type=reactionMessage text=null grupo=nao  key.id(alvo)="3ABD5E3782527A40B05C"    emoji="😂"
id=3852 type=reactionMessage text=null grupo=sim  key.id(alvo)="3EB036F54948B6DD993625"  emoji="🙏"
```
(50 reações são 1:1, 121 em grupo — do diagnóstico anterior; o campo `text` da coluna fica nulo, o
emoji real está em `raw_payload.data.message.reactionMessage.text`.)

## Bloco 4 — A reação toca o pipeline reativo?

**4.1 Matching de regras — dispara resposta? NÃO. Mas é avaliada em 1:1.**
`avaliarAutoResposta` pula grupo/fromMe cedo (`ProcessIncomingWhatsappMessage.php:294`), então
**reação de grupo nem chega às regras**. Em 1:1 ela chega e é avaliada (`:327`
`$matcher->match(..., $data->text=null, ...)`), mas `RuleMatcher` retorna `[]` para texto nulo
(`app/Whatsapp/AutoReply/RuleMatcher.php:54-55` `if ($text === null) return [];`) → **nunca casa
regra, nunca dispara resposta**. E não vira unmatched: `UnmatchedMessage::record` corta texto vazio
(`app/Models/UnmatchedMessage.php:30-32`).

**4.2 Conta em métrica do painel? SIM.** `PainelMetrics::resumo`
(`app/Metrics/PainelMetrics.php:77-83`) conta `IncomingMessage` com `from_me=false` **sem filtro de
tipo**: reação 1:1 infla `recebidas` (`:82`) e reação de grupo infla `grupos` (`:83`). A página de
Logs também lista reações como "Mensagens recebidas" (visto no diagnóstico anterior).

**4.3 Pode disparar robô / IA? Regra: NÃO. IA: SIM em 1:1 se a IA estiver ligada (OFF por padrão).**
No ramo "nada casou" (`:328`), antes do unmatched vem `if ($guard->aiEligible($account->id, $jid))
ClassifyWithAi::dispatch($message->id, ...)` (`:333-335`). `aiEligible($accountId, $jid)`
(`app/Whatsapp/AutoReply/AntiBanGuard.php:285`) **não olha o texto** — logo uma reação 1:1 de um
contato com IA elegível dispararia um job de classificação da IA (gasto de cota; potencial resposta).
A IA é OFF por padrão → ramo **dormente**, mas estruturalmente aberto.

**4.4 Entra no Kanban / gera evento? SIM só em 1:1; NÃO em grupo.**
`popularContato` retorna cedo para grupo/fromMe (`ProcessIncomingWhatsappMessage.php:262`). Em 1:1
ele roda → dispara `event(IncomingMessageStored)` (`:127`) e `ContactChannelWindow::touchWindow`
(`:123`). O listener `app/Listeners/UpdateKanbanFromEvent.php` reage a esse evento → **reação 1:1
cria/toca card no Kanban e reabre a janela de 24h.** Em grupo, nada disso (barrado por `isGroup`).

**Filtros existentes que já barram reação:** apenas os de **grupo/fromMe** (`:262`, `:294`) — não há
nenhum filtro por **tipo de mensagem** (reaction) em lugar algum do inbound.

## Bloco 5 — Front

**5.1** Componente: `App\Livewire\Conversas` (`thread()` em `app/Livewire/Conversas.php:494`); blade:
`resources/views/livewire/conversas.blade.php` (loop das bolhas ~linha 188+).

**5.2 O front NÃO tem tratamento de reação — renderiza cada linha como bolha.** O loop de `thread()`
(`Conversas.php:494`) não filtra tipo; para cada `IncomingMessage` monta um item com
`'preview' => MessagePreview::for($m->type, ...)` (`:505`). Para `reactionMessage`,
`MessagePreview::for` devolve `['emoji'=>reactionMessage.text, 'label'=>'reagiu']`
(`app/Whatsapp/MessagePreview.php:121-123`). No blade, o bloco de mídia recebida só cobre
`in_kind` image/audio (`:238,:252`); reação tem `in_kind=null`, então cai no
`<x-msg-preview>` (`:264-265`) → renderiza **emoji + "reagiu"** como uma **bolha própria** com
horário/separador/agrupamento. **Esse é o caminho que produz a bolha** (confirmado: a reação chega
como linha no Bloco 3 e o loop a transforma em bolha). Obs.: a lista lateral de conversas
(`Conversas.php:389`) também usa esse preview, então uma reação recente aparece como "última
mensagem" da conversa.

---

## Recomendação de correção (NÃO aplicada)

O gargalo é **ingestão (backend)**. Correção certa e mínima: tratar reação **na entrada**, antes de
virar mensagem de thread. Opções (para o Fabio decidir no prompt de correção):
- **(A) Descartar da thread na normalização:** em `EvolutionProvider::normalizeIncoming`
  (`:252`) e `CloudApiProvider::normalizeIncoming` (`:142`), detectar `reactionMessage`/`reaction` e
  retornar `null` (não persiste) — mais simples, mas perde a reação por completo.
- **(B) Persistir à parte / anexar ao alvo (recomendado):** gravar a reação num modelo próprio (ou
  marcá-la) usando `reactionMessage.key.id` / `reaction.message_id` para ligá-la à mensagem reagida,
  e exibi-la agregada na bolha-alvo (estilo WhatsApp). Ponto de corte natural:
  `ProcessIncomingWhatsappMessage::persistir` (`:234`) + ajuste de exibição em `Conversas::thread()`
  (`:494`) e blade.
- Em **qualquer** opção, garantir que reação **não** entre em `PainelMetrics` (`:82-83`), não dispare
  `ClassifyWithAi` (`:333`) e não gere evento de Kanban (`:127`) — o corte na ingestão (A/B) já
  resolve os três de uma vez.

---

## Confirmação de que nada foi tocado
- `php artisan test`: **599 verdes** (2248 assertions) — inalterado.
- `git status` (final): **working tree clean** exceto o novo `docs/relatorios/2026-07-03-diagnostico-reacoes.md`.
- Nenhum arquivo de código/schema/migration alterado; nenhum commit/push; nenhuma escrita no banco/Redis.
