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

## Notas
- `raw_payload` guarda o payload **completo** — fonte de verdade pra evoluir o parsing depois sem perder dados.
- Migrations: `database/migrations/2026_06_29_00000{1,2,3}_*`.
