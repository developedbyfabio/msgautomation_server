# Camada 2 — Auto-resposta ligada (Fatia 3)

Liga **recebimento → freios → match → auto-resposta**, com **allowlist + controle por contato** e
**agenda automatica**. Sem IA, sem UI (UI e a Camada 4). **DORMENTE por padrao.**

## Dormencia (estado real)
- `auto_reply_settings.enabled` (kill switch) = **OFF**.
- `auto_reply_settings.reply_policy` = **allowlist**.
- Contato novo entra como `auto_reply_mode = default` → sob allowlist **nao responde**.
- Resultado: **nada dispara** ate o Fabio (a) aprovar um contato (`on`) e (b) ligar o kill switch.
  Ligar e **gate do Fabio**, acordado.

## Controle por contato
- `auto_reply_settings.reply_policy`: `allowlist` (default) | `all`.
- `contacts.auto_reply_mode`: `default` | `on` | `off` (substitui o antigo `auto_reply_opt_out`,
  depreciado; backfill `opt_out=true → off`).
- Portao: allowlist responde so `on`; `all` responde todos exceto `off`.

## Agenda automatica
Cada mensagem **individual recebida** (`fromMe=false`, nao-grupo) faz upsert em `contacts`
(atualiza `push_name`). Novo contato = `default`. Grupos (`@g.us`) ficam fora.

## Motor de match (RuleMatcher)
- Regras em `auto_reply_rules` (`match_type`, `match_value`, `response_text`, `enabled`, `priority`).
- Normalizacao nos dois lados: fold de acento + lowercase + trim + colapso de espacos.
- `exact` | `starts_with` | `contains`. **`contains` = palavra inteira** (whole-word, multibyte) —
  evita "ola" casar em "escola". Trocar por substring: so `RuleMatcher::containsWholeWord()`.
- Ordem: `priority` asc, depois `id` asc; **primeira habilitada que casa vence**; uma resposta.
- Sem match / texto nulo / midia sem texto → **silencio**.

## Fluxo (tudo na fila)
`ProcessIncomingWhatsappMessage` (apos persistir): popula contato → se **nao** fromMe/grupo,
**casa regra** e **contato aprovado** → enfileira `SendAutoReply` com **delay humano**
(`delay_min..max`). O `SendAutoReply`/`Sender` aplica os freios volateis + **R2** (re-check kill
switch + portao + janela imediatamente antes do POST) e envia.

## Logging (decisao de design — a confirmar com o Fabio)
`auto_reply_logs` registra **tentativas de resposta** (contato aprovado + regra casou): resultado
`sent` ou `blocked` por freio volatil (`kill_switch`, `fora_da_janela`, `rate_contato`,
`intervalo_minimo`, `teto_minuto`, `teto_dia`) ou `failed`. **Silencios estruturais** (fromMe, grupo,
sem regra, contato nao-aprovado) **nao** geram log, pra evitar uma linha por mensagem recebida. Se
quiser auditoria total (logar todo "nao respondi e por que"), e uma mudanca pequena.

## Go-live (passo-a-passo do Fabio, acordado — NAO automatizado)
1. Aprovar o contato-alvo: `auto_reply_mode = on` (UI da Camada 4, ou tinker escopado).
2. Criar 1 regra de teste, ex.: `contains` "horario" → "Atendo das 8h as 18h".
3. Ligar o kill switch: `enabled = true`.
4. Do segundo numero, mandar "horario" pro numero conectado.
5. Confirmar **uma** resposta; conferir `auto_reply_logs` (status `sent`).
6. Desligar o kill switch (`enabled=false`) se quiser voltar a dormente.
