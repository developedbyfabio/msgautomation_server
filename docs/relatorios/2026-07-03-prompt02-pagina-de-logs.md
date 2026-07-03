# Fila noturna — Prompt 02: Página de Logs/Eventos + status FAILED da Meta — 2026-07-03

Status: **VERDE. 560/560 (baseline 554/554 do prompt 01).** Webhook Evolution e
pipeline reativo intocados (smoke pós-deploy: login 200, /logs sem auth 302,
webhook com token inválido 401, serviços ativos).

Nota de continuidade: a sessão anterior caiu no meio deste prompt (migration já
tinha rodado; código quase completo). Retomado do estado parcial: corrigido um
helper de teste com recursão infinita (`tela()` chamava a si mesmo) e um
`raw_payload` faltante no setup de teste. Nada do desenho mudou.

## O que mudou

**A falha silenciosa, corrigida na origem (o coração do prompt):**
- `ProcessIncomingWhatsappMessage` agora, no canal Cloud, varre `statuses[]` do
  payload da Meta ANTES do descarte de "não é mensagem": todo `status=failed`
  é PERSISTIDO — o `auto_reply_logs` correspondente (por wamid) vira
  `status=failed` + `motivo=meta_{code}`, e nasce um `system_events` com
  code/title/recipient legíveis. O 130497 nunca mais some.
- Idempotente por `ref` único (`status-failed:{wamid}`) — re-entrega da Meta
  (at-least-once, 36h de retry) não duplica evento (provado por teste).
- D5 preservada no resto: sent/delivered/read seguem ignorados com log leve.
  O 200 rápido do webhook não muda (tudo roda no worker).

**Nova tabela `system_events` (migration ADITIVA):**
- account_id NULL = evento GLOBAL de servidor; channel_id, type
  (envio_falhou | canal | erro_sistema), level, title, detail JSON (nunca
  segredo — contexto de log é DESCARTADO de propósito), ref único, occurred_at.
- `Channel::updated` (status mudou) → evento de canal (conexão/desconexão).
- `Log::listen` (warning+) → evento global `erro_sistema` best-effort com
  anti-loop (gravar evento nunca derruba nem re-dispara o caminho principal;
  try/catch em tudo).

**Página `/logs` (Livewire + Flux, item "Logs" no menu):**
- Timeline unificada SOMENTE LEITURA: recebidas (com quem enviou + canal),
  envios ok, envios falhos/bloqueados (com motivo e code da Meta em destaque
  vermelho + detalhe expansível), sem match (`unmatched_messages`), eventos de
  canal, erros do sistema.
- Filtros: tipo, canal (Evolution/Cloud), período (hoje/24h/7d — "hoje" em
  dia DE SÃO PAULO, não UTC). Horário exibido SEMPRE em SP via macro
  `paraExibicao` (provado por teste: 12:00 UTC aparece 09:00).
- Paginação incremental ("carregar mais", janela de 50).
- Multi-tenant: escopo normal da conta; único bypass nomeado é a leitura dos
  eventos GLOBAIS (account_id NULL, erros de servidor) — marcados "[sistema]".

## Testes (6 novos — LogsPageTest)
Failed da Meta marca envio como falho + evento legível com code/title +
idempotência de re-entrega + aparece na página; categorias ok/recebida/sem
match; filtros tipo/canal/período; mudança de status de canal vira evento;
isolamento (usuário da A não vê eventos nem envios da B); fuso SP na exibição.
**Suíte completa: 560/560 (2.036 assertions).** `TenantIsolationTest` intacto.

## Deploy
Migration `2026_07_03_040000_create_system_events_table` aplicada (aditiva);
`npm run build`; restart serve+worker; smoke ok (acima).

Próximo da fila: **03 — Conversas: input (Enter envia / Ctrl+Enter quebra /
textarea cresce / emojis).**
