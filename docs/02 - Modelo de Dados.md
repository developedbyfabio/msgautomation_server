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

### rule_responses
| coluna | tipo | nota |
|---|---|---|
| id | bigint PK | |
| auto_reply_rule_id | FK auto_reply_rules | cascadeOnDelete |
| response_text | text | uma das respostas (sorteio no envio) |

Engine: `RuleMatcher` casa a regra se **qualquer** gatilho casa (cai pro legado se nao ha filhas).
`RuleResponder` escolhe **uma** resposta (aleatoria) e processa placeholders **no envio**
(`{nome}`,`{saudacao}`,`{data}`,`{hora}`). Migracao `..._000011` backfilla as regras antigas.

## Notas
- `raw_payload` guarda o payload **completo** — fonte de verdade pra evoluir o parsing depois sem perder dados.
- Migrations: `database/migrations/2026_06_29_*` (ate `..._000011`).
