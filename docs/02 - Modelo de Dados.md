# Modelo de Dados (msgautomation)

Banco `msgautomation` (MySQL 8). Multi-tenant **em mente**, sem tenancy implementado na Camada 1.

## accounts
Ancora de conta/usuario. Single-user agora, mas tudo pendura aqui.

| coluna | tipo | nota |
|---|---|---|
| id | bigint PK | |
| name | string | |
| timestamps | | |

## channels
Instancia de WhatsApp na Evolution vinculada a uma account. **Sem segredos** — o token/api-key
da instancia vive no `.env`, nunca no banco.

| coluna | tipo | nota |
|---|---|---|
| id | bigint PK | |
| account_id | FK accounts | cascadeOnDelete |
| instance | string(100) UNIQUE | nome da instance na Evolution (ex.: `fabio-pessoal`) |
| status | string(32) | `disconnected` / `connecting` / `connected` |
| remote_jid | string nullable | JID do proprio numero (quando conectado) |
| connected_at | timestamp nullable | |
| last_event_at | timestamp nullable | ultimo evento recebido |
| timestamps | | |

## incoming_messages
Mensagens recebidas via webhook. Camada 1: so registra.

| coluna | tipo | nota |
|---|---|---|
| id | bigint PK | |
| account_id | FK accounts | cascadeOnDelete |
| channel_id | FK channels nullable | nullOnDelete |
| instance | string(100) | denormalizado p/ idempotencia antes de resolver o channel |
| evolution_message_id | string(191) | id da mensagem na Evolution (`data.key.id`) |
| remote_jid | string | remetente/conversa (indexado) |
| from_me | boolean | default false |
| push_name | string nullable | nome exibido do contato |
| type | string(64) | `conversation`, `extendedTextMessage`, `imageMessage`, ... |
| text | text nullable | texto/legenda extraido |
| raw_payload | json | payload bruto integral do webhook |
| received_at | timestamp | de `data.messageTimestamp` (indexado) |
| timestamps | | |

### Idempotencia (regra dura)
Indice unico **`(instance, evolution_message_id)`** (`incoming_messages_idem_unique`).
Re-entrega do mesmo evento -> o `create` lanca `UniqueConstraintViolationException`, que o job
**captura e ignora**. Nunca duplica linha, nunca quebra.

## contacts (campos do Refino 2)
- `auto_reply_mode` (`default|on|off`) — modo por contato (substitui `auto_reply_opt_out`, depreciado).
- `notes` — anotacoes internas. `push_name` — nome (auto-populado ou dado pelo usuario).
- `saved` (bool, S4) — true quando o usuario nomeou/adicionou o contato pelo painel.

## auto_reply_rules + filhas (S7 — regras avancadas, nao-destrutivo)
A tabela `auto_reply_rules` foi **mantida**; suas colunas `match_type`/`match_value`/`response_text`
viram **cache denormalizado** do 1o gatilho / 1a resposta (back-compat + fallback). A verdade fica
nas filhas:

### rule_triggers
| coluna | tipo | nota |
|---|---|---|
| id | bigint PK | |
| auto_reply_rule_id | FK auto_reply_rules | cascadeOnDelete |
| match_type | string(16) | `exact`/`contains`/`starts_with`/`regex` |
| match_value | string | gatilho |
| precision | string(16) | `exato` (default) / `tolerante` (fuzzy, S5) |
| fuzzy_level | string(8) null | `baixa`/`media`/`alta` (quando tolerante) |

### rule_responses
| coluna | tipo | nota |
|---|---|---|
| id | bigint PK | |
| auto_reply_rule_id | FK auto_reply_rules | cascadeOnDelete |
| response_text | text | uma das respostas (sorteio no envio) |

### Regras v2 (aditivo — migracao `..._000012`)
`auto_reply_rules` ganhou: **`cooldown_mode`** (`global`/`sempre`/`1x_dia`/`cada_n`),
**`cooldown_minutes`** (p/ `cada_n`), **`scope`** (`global`/`contatos`). Nova tabela
**`rule_contacts`** (`auto_reply_rule_id`, `contact_id`, unique) liga regra a contatos do
escopo `contatos`. Defaults preservam o comportamento atual (global/exato) — sem backfill.

Engine: `RuleMatcher` filtra por **escopo** (S3) e casa se **qualquer** gatilho casa, exato ou
**tolerante** (S5: Levenshtein por token whole-word, com guarda-corpos). `RuleResponder` sorteia
**uma** resposta + placeholders **no envio** (`{nome}`,`{saudacao}`,`{data}`,`{hora}`). `AntiBanGuard`
aplica o **cooldown por regra** (S2, via `auto_reply_logs`) substituindo o rate-global da regra,
com os **tetos de volume** como piso. `RuleTester` (S4) faz dry-run sem enviar.

## auto_reply_settings — toggles por freio (aditivo, `..._000013`)
Cada freio-throttle global ganhou um liga/desliga (bool, **default true** = preserva o
comportamento): `window_enabled`, `min_interval_enabled`, `per_minute_enabled`,
`per_day_enabled`, `contact_rate_enabled`. Desligado = aquele freio **nao bloqueia**
(o `AntiBanGuard` respeita). `skip_groups`/`warmup_enabled` ja eram seus proprios toggles.
Guardas estruturais **fromMe** e **idempotencia** NAO tem toggle (sempre ativos).

O **intervalo por contato** (modo `global` de cooldown) passou a ler o **valor atual** de
`contact_rate_seconds` comparando com o ultimo auto-reply ao contato em `auto_reply_logs`
(antes usava cache com TTL congelado no envio -> nao refletia mudanca do valor).

## secrets — cofre de senhas (`..._000014`)
Escopo account. Valor **cifrado em repouso** com chave **dedicada** (`SECRETS_KEY`, separada do
`APP_KEY`; AES-256 via Encrypter dedicado em `SecretCipher`). Colunas: `account_id`, `nome`
(label nao-secreto), `value_encrypted` (cifrado), `categoria`/`notes` (opcionais), unique
(account_id, nome). Model `Secret` com `$hidden = [value_encrypted]`.

`SecretVault`: `put` (cifra), `reveal` (decifra sob demanda), `names` (so nomes), `resolve`
(`{senha:nome}` -> valor, EM MEMORIA no envio), `redact` (`-> [senha: nome]` p/ log), `mask`
(`-> ••••` p/ testador). Regras referenciam por `{senha:nome}` (guardam so a referencia).

Seguranca: o valor decifrado vai SO na mensagem enviada; **nunca** em `auto_reply_logs` (Sender
grava a redacao) nem em logs de app. Guarda de escopo: regra com `{senha:...}` exige
`scope=contatos` e gatilho estrito (sem fuzzy). Limite conhecido: o app decifra pra responder
sozinho e a senha vai em texto pelo WhatsApp pra quem disparar — por isso o escopo restrito.

## groups — cache do nome de grupo (`..._000015`)
Escopo account. `remote_jid` (...@g.us) + `subject` (nome) + `resolved_at`, unique
(account_id, remote_jid). `GroupNameResolver::nameFor` le so o cache (render nunca bate na
Evolution); `ensure` dispara `ResolveGroupName` (job, dedupe 5 min) que busca o subject em
`GET /group/findGroupInfos/{instance}` e grava. Exibicao apenas.

## Captura e exibicao de mensagens (S5/S6)
- **Catch-all:** o `EvolutionDriver` SEMPRE registra qualquer `messageType` (reaction/sticker/
  location/poll/desconhecido) — tipo resolvido (messageType -> inferido, pulando
  messageContextInfo -> 'unknown'); nunca descarta (so falta key.id/jid). Reacoes chegam via
  `messages.upsert`. `incoming_messages.text` segue sendo a legenda/conversation (matcher).
- **Preview (display):** `MessagePreview::for(type,text,raw)` deriva icone/label/legenda/emoji
  (componente `x-msg-preview`), sem tocar em matcher/freios.

## Precedencia de regras (Fatia 0)
Sem setas manuais. Quando >1 regra casa, vence a de gatilho MAIS ESPECIFICO:
tipo (exact>starts_with>contains>regex) -> tamanho -> escopo (contatos>global) ->
precisao (exato>tolerante) -> id menor. `RuleMatcher::allMatching()` ordena todas;
`match()` = a primeira. `priority` mantido no schema (sem uso/UI). `RuleConflictDetector`
avisa sobreposicao (regra×regra; extensivel a fluxo).

## Fluxos — menus condicionais (Fatia A, `..._000016`)
Determinístico, sem IA. Tabelas (escopo account): `flows` (name, enabled, scope,
timeout_seconds, invalid_message, root_node_id), `flow_triggers` (gatilhos de entrada,
mesmo formato de rule_triggers), `flow_contacts` (escopo contatos), `flow_nodes`
(arvore: parent_node_id, kind menu|final, message, ordem), `flow_options`
(input, label, next_node_id), `flow_sessions` (estado por contato: current_node_id,
status active|completed|expired|cancelled, last_activity_at, expires_at).

`FlowEngine` (sem enviar — devolve diretiva texto+status): sessao ativa tem prioridade
(navegacao, nunca cai nas regras); fluxo de entrada vence regra; timeout expira
preguicosamente; opcao invalida re-pergunta; final encerra; reentrada reinicia; sair/
cancelar encerra. No envio, resposta de fluxo e ISENTA do intervalo-por-contato
(flag `flow` no Sender/AntiBanGuard); resto dos freios + placeholders/{senha}/redacao
mantidos. **Robô inalterado enquanto nenhum fluxo estiver enabled (0 hoje).**

UI (Fatia B): pagina `/fluxos` (construtor arvore-outline) — lista, criar rascunho + nó raiz,
ligar/desligar (valida gatilho+raiz; **guarda de senha: nó com `{senha:...}` exige escopo
contatos pra ligar**), editor de config + gatilhos de entrada + nós/opcoes (destino: nó
existente | novo sub-menu | nova resposta final) + preview. So construcao; runtime intacta.
**Atencao:** ligar um fluxo com o kill switch ON deixa o menu AO VIVO. Guarda de segredo
completa (gatilho estrito) + testador estendido = Fatia C.

## Notas
- `raw_payload` guarda o payload **completo** — fonte de verdade pra evoluir o parsing depois sem perder dados.
- Migrations: `database/migrations/2026_06_29_*` (ate `..._000016`).
