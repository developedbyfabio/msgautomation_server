# Fatia 5 — Nó de Handoff pra humano (MOTOR do fluxo) — 2026-07-04

**Status: ENTREGUE.** Baseline 709 → **715 verdes** (+6, 2685 assertions), `TenantIsolationTest` 28,
suítes de fluxo (`FlowEngine/FlowPipeline/FlowSim/Fluxos`) e Kanban verdes. **Zero migration**
(kind e status são `string(16)` — `handoff`/`handed_off` cabem; aditivo puro).

## Git no início
`working tree clean`, HEAD `7d66edf` (fatia 6).

## Como `kind` é constrangido
`flow_nodes.kind` = `string(16)` **sem enum de banco** (migration `..000016:52`); a semântica vive no
app (`isFinal()`; UI só cria menu/final). **Adicionei `FlowNode::isHandoff()`** — validação app-level,
**sem migration**. `flow_sessions.status` idem (`string(16)`) → terminal **`handed_off`** (aditivo;
`activeSession` só considera `'active'`, então handed_off é terminal por construção). Escolhi
`handed_off` (não `completed`) porque distingue no ledger o encerramento por atendimento humano.

## Pontos de REUSO (o coração do "não reinventar")
- **Mute:** `Contact::query()->updateOrCreate(['account_id','remote_jid'], ['auto_reply_mode' => 'off'])`
  — o EXATO statement que a UI usa (`Conversas::muteConfirmed`, `app/Livewire/Conversas.php:201-203`).
  Não há service compartilhado de mute; o mecanismo É essa escrita escopada conta+contato. O gate
  (`AntiBanGuard::contactGate`) e o catch-all (Fatia 4) já respeitam `off` — nada a mudar.
- **Kanban:** `BoardEngine` (o motor de cards). O `apply()` por regras não serve (regras são **dados
  por conta**, customizáveis/apagáveis — o handoff precisa mover SEMPRE), e o move manual vive inline
  na UI. Adicionei ao **próprio BoardEngine** o método público `moveToColumnSlug(slug, accountId,
  remoteJid, eventType, eventRef)` com a MESMA semântica do motor: board default, card por contato
  (cria se ausente), no-op na mesma coluna, **idempotência por (card, event_type, event_ref)** (o
  unique já existente), `CardTransition` com `cause='handoff'`, `touch`. Erro do Kanban é isolado
  (try/catch no engine de fluxo — observador nunca derruba o pipeline, como no listener).

## Execução do handoff no `FlowEngine`
`emit()` ganha o branch `if ($node->isHandoff()) return $this->emitHandoff(...)`. `emitHandoff`:
1. sessão → `handed_off` (terminal);
2. mute reusado (acima);
3. `BoardEngine::moveToColumnSlug('em_atendimento', ...)` (try/catch isolado);
4. `FlowNodeReached` (K-1, como os demais nós);
5. retorna `['text' => $node->message, 'status' => 'handed_off']` — a despedida segue o **mesmo**
   caminho de dispatch (`dispatchFlowReply` → `SendAutoReply` → `Sender`/freios).
`simEmit` (testador dry-run): handoff mostra mensagem/status `handed_off` **sem efeitos** colaterais.

## SURPRESA REGISTRADA (descoberta no 3.1) e resolução
O fluxo de envio (`mode 'auto'`) passa pelo **gate de contato em DOIS pontos** (`check()` passo 2 e
`volatileRecheck` R2). Como o handoff seta `off` **antes** do envio (o dispatch é assíncrono/na fila),
**o próprio mute bloquearia a despedida do handoff** ('opt_out'). Alternativas descartadas: mutar
depois do envio (fila = não-determinístico, janela de corrida em que o catch-all re-dispara o menu);
enviar como 'manual' (ignoraria o kill switch — pior). **Resolução cirúrgica, flag-gated:** a
diretiva `handed_off` viaja como `handoff: true` por `dispatchFlowReply → SendAutoReply → Sender`, e:
- `AntiBanGuard::check(..., bool $handoff = false)`: pula **só** o `contactGate` quando handoff
  (fromMe/grupo/kill switch/janela/tetos **continuam valendo**);
- novo método aditivo `volatileRecheckHandoff(accountId)`: o mesmo R2 **menos** o gate de contato
  (kill switch + janela valem).
Com `handoff=false` (default em todos os call sites existentes), o comportamento é **byte-idêntico**.
Ajuste necessário num dublê de teste (`AutoReplySendTest` estende `AntiBanGuard` anonimamente — a
assinatura do `check` acompanhou o novo param opcional; fatal de compatibilidade sem isso).

## Testes (`tests/Feature/FlowHandoffTest.php`, 6 — pipeline real, Sender contra Http::fake)
1. **4 efeitos:** menu→'1'→handoff: despedida **enviada** (não bloqueada pelo próprio mute); contato
   `off`; card em `em_atendimento`; sessão `handed_off`.
2. **Movimento determinístico:** com **TODAS** as BoardRules desativadas, o handoff ainda cria/move o
   card pra `em_atendimento` com `CardTransition cause='handoff'` (não depende de regra). *(Achado:
   com as regras default, a regra `resposta_enviada` já move no envio do menu e o move do handoff
   vira no-op de mesma coluna — semântica correta, registrada.)*
3. **Pausado mas recebendo:** próxima mensagem do contato → **persistida** (`incoming_messages` tem a
   linha; ingestão/exibição NÃO bloqueadas) e **sem** auto-reply/catch-all (mesmo em modo AUTO com
   fluxo padrão — mute respeitado).
4. **Reativar restaura:** `auto_reply_mode='default'` (mecanismo existente) → próxima mensagem
   re-dispara o catch-all (nova sessão ativa; menu enviado de novo).
5. **Isolamento:** handoff na conta A muta só o contato de A; o contato do MESMO jid na conta B segue
   `on`; nenhuma sessão em B.
6. **Regressão menu/final:** fluxo normal continua idêntico (final `completed`; contato NÃO mutado).

## Confirmações
- Ingestão de contato mutado **não** foi bloqueada (teste 3) — só o auto-reply é suprimido.
- `menu|final` inalterados; suítes de fluxo/Kanban/ingestão verdes sem alteração (exceto a assinatura
  do dublê citada).
- Isolamento por conta em tudo (mute escopado, BoardEngine `runAs(accountId)`, teste 5).
- Fora de escopo respeitado: editor (5b), templates (7), seed (8), rampa warmup — não tocados.

## Contagem
Antes: **709 verdes / 2665 assertions**. Depois: **715 verdes / 2685 assertions** (+6).

## Commit
Local, sem push (Fabio empurra). Hash reportado na resposta.
