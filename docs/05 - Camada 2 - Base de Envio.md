# Camada 2 — Base de Envio (Fatia 2, implementada)

Primeiro momento em que o sistema ENVIA. Anti-ban e prioridade. Esta fatia entrega a
base de envio e prova UM envio manual. **Ainda NAO liga regras ao recebimento** (Fatia 3),
sem IA (Camada 3), sem UI (Camada 4).

## Componentes
- **Driver:** `EvolutionDriver::sendText($instance,$to,$text)` (atras do contrato
  `WhatsappGateway`). Endpoint v2.3.7 `POST /message/sendText/{instance}`, body `number`+`text`,
  auth header `apikey`. `number` aceita jid (normalizamos pra digitos). Retorna `SentMessageData`;
  falha lanca `WhatsappSendException`.
- **Freios** (`App\Whatsapp\AutoReply\AntiBanGuard`): decide enviar/parar com motivo.
- **Contadores** (`Throttle`): cache com TTL (em prod = Redis do app), escopo account, dia em
  America/Sao_Paulo. Incrementam **so no envio efetivo**.
- **Sender** (`Sender`): claim idempotente + freios + R2 + envio + log.
- **Job** `SendAutoReply`: caminho auto (implementado e testado; **nao** disparado ainda).
- **Comando** `php artisan whatsapp:send {jid} {texto}`: envio MANUAL.

## Dois caminhos (R1)
- **manual** (`whatsapp:send`, futura intervencao humana): passa **so pelos tetos protetivos**
  (intervalo minimo, teto/min, teto/dia). **Nao** passa por kill switch, janela nem opt-out.
- **auto** (Fatia 3): `fromMe` -> grupos -> opt-out -> kill switch -> janela -> rate por contato
  -> tetos. Primeira condicao que falha para o envio (silencio).

## R2 — re-check antes do POST
No caminho auto, o `Sender` re-checa **kill switch + opt-out + janela** imediatamente antes de
postar (o estado pode mudar entre o enfileiramento, o `->delay()` humano e o envio).

## Idempotencia
`auto_reply_logs.incoming_message_id` e **unico**. O `Sender` faz **claim** (cria a linha antes
de enviar); re-entrega da mesma mensagem cai no unique e **nao reenvia**. Envios manuais tem
`incoming_message_id` nulo (multiplos NULL sao permitidos).

## Settings (tabela, defaults aprovados)
`auto_reply_settings` (1 linha/account). Kill switch flipa **instantaneo** (sem `.env`/restart).

| Parametro | Default |
|---|---|
| `enabled` (kill switch auto) | **false (OFF)** |
| janela | 08:00–20:00 |
| `min_interval_seconds` | 30 |
| `per_minute_cap` | 4 |
| `per_day_cap` | 40 |
| `contact_rate_seconds` | 1800 (30 min) |
| `skip_groups` | true |
| `warmup_enabled` | false |
| `delay_min/max_seconds` | 3 / 15 |

Flipar kill switch (exemplo, via tinker): `App\Models\AutoReplySetting::query()->update(['enabled'=>true])`
(escopar por account em multi-tenant).

## auto_reply_logs (status)
`pending` (claim) -> `sent` | `blocked` (com `motivo`: kill_switch, fora_da_janela, opt_out, grupo,
from_me, rate_contato, intervalo_minimo, teto_minuto, teto_dia) | `failed` (erro_envio).
