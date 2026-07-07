# Servidores — mensagens configuráveis + cadência de re-aviso por regra — 2026-07-07

Git no início: HEAD `7386c24`, branch `master`, working tree limpo. **`APP_ENV=local`**
(DEV `192.168.11.210`). Remote `msgautomation_server`. **Sem push.** Produção
`187.127.24.165`/Nextgest/nginx/Tunnel: intocados.

**Baseline: 1068 verdes / 4163.** **Final: 1077 verdes / 4201** (+9). Zero regressão.
**Commit: `8f1f8da`.**

---

## 1. Cadência de re-aviso por regra/nível (no painel)

Cada regra ganhou `warning_repeat_s` e `critical_repeat_s` (aditivas, nullable):
- **NULL/0 = "avisar 1 vez"** (não re-avisa).
- **>0 = "re-avisar a cada N"** (segundos no banco; a UI edita em **minutos**).

Isso **sobrescreve** o default da S3 (onde só `critical` não-reconhecido re-notificava a cada
`cooldown_s`). Agora é decisão do dono por regra: **warning pode re-avisar** de hora em hora;
**critical pode ser 1 vez** só. O `cooldown_s` legado permanece na coluna (não é mais usado
para re-aviso; a cadência vem dos novos campos).

Onde a cadência é aplicada — `SendServerAlert::pendingReminders`:
```php
$repeat = $rule?->repeatSecondsFor((string) $i->level);   // warning_repeat_s | critical_repeat_s
return $repeat !== null && $i->last_notified_at->addSeconds($repeat)->isPast();
```
Só incidentes **firing** (ack silencia — `status=firing` exclui `acknowledged`) já notificados
(`notified_level == level`). `resolved` continua mandando a resolução **1 vez**
(`notified_resolved_at`).

Seed/migração preserva o comportamento S3: `critical_repeat_s <- cooldown_s` nas regras
existentes; `warning_repeat_s = null` (warning 1 vez). `AlertRuleDefaults` idem para contas
novas.

`SendServerAlert::hasPending` (decide se o command despacha o job) passou a considerar re-aviso
de **qualquer nível** — mas só quando a regra tem cadência (`*_repeat_s > 0`) para o nível do
incidente (via `whereExists` na regra), evitando job no-op perpétuo em incidentes "avisar 1 vez".

## 2. Mensagem editável (variáveis)

O dono edita o **texto** por regra/nível na tela Alertas. Variáveis substituídas no envio
(documentadas na própria tela): **`{servidor}` `{metrica}` `{valor}` `{nivel}` `{particao}`**
(`{particao}` só faz sentido em disco). Vazio → **texto padrão sensato**
(`🔴 {servidor}: {metrica} {nivel} ({valor})` / `✅ {servidor}: {metrica} normalizado`).

`{valor}` é formatado por métrica: `92%` (cpu/ram/swap/disk), `1.5/núcleo` (load),
`120s sem reportar` (watchdog).

## 3. Múltiplas mensagens (rotação/sequência)

Nova tabela `server_alert_messages` (`account_id, rule_id, level, position, text`) — lista por
`(regra, nível)` para warning/critical + um texto único `resolved`. `server_incidents.notify_count`
(aditiva) é o índice de rotação:
- **1º disparo → 1ª mensagem** (`notify_count = 0`).
- **A cada re-aviso do mesmo incidente → avança** (`notify_count` incrementa no envio).
- **Ao acabar a lista → repete a última** (`min(notify_count, tamanho-1)`) — decisão registrada.

`AlertMessageResolver` centraliza tudo (rotação + variáveis + default) e é usado tanto pelo
**WhatsApp** quanto pela **conversa de sistema** (Atendimento) → **mesmo texto** nos dois. Os
re-avisos também aparecem na conversa de sistema (o job os grava com o texto rotacionado; as
transições firing/escalada/resolved seguem gravadas pelo `AlertNotifier`).

Exemplo (o "continua embucetado" do dono): 1ª `🔴 {servidor}: disco em {valor}`; 2ª
`⚠️ {servidor} continua com disco em {valor}`; 3ª `🚨 {servidor} AINDA com problema` → depois
repete a 3ª. **Validado ao vivo** no `laravel-dev` via `AlertMessageResolver` (notify_count
0→A, 1→B, 2→C, 3→C; `{servidor}=Laravel Dev`, `{valor}=93%`).

## Transporte e agrupamento (intocados / preservados)

- **Transporte inalterado**: continua `ProviderRegistry->for($channel)->sendText` direto (NÃO
  o Sender de Campanha). A feature só resolve o texto e respeita a cadência.
- **Agrupamento anti-tempestade preservado**: o job segue mandando uma mensagem por contato,
  mas **cada linha agora é a mensagem própria do incidente** (custom + variáveis + rotação);
  acima de `storm_cap` vira resumo; burst cap por janela mantido.

## Migration (`2026_07_07_000007_add_alert_cadence_and_messages.php`, aditiva, confirmada)
- `server_alert_rules += warning_repeat_s, critical_repeat_s` (nullable); seed
  `critical_repeat_s <- cooldown_s`.
- `server_incidents += notify_count` (default 0).
- `server_alert_messages` (nova): rule_id cascade, level, position, text, index (rule,level,position).

## Testes (`ServersMensagensConfiguraveisTest`, 9)
- **1 vez não re-notifica** (`critical_repeat_s = null` → 1 envio mesmo após 2h).
- **A cada N re-notifica no intervalo** (1h: não repete aos 30min; repete após 70min).
- **Warning pode re-avisar** quando configurado (`warning_repeat_s = 1800`).
- **Variáveis substituídas**: texto enviado == `srv-a esta com CPU em 97% (critical)`.
- **Rotação A→B→C→repete C** (asserção do texto exato a cada re-aviso).
- **Resolução com texto próprio, 1 vez** (não re-manda).
- **Resolver default** quando sem mensagem cadastrada.
- **UI grava cadência + mensagens** (15min → 900s; 2 msgs critical + resolved) e **toggle off →
  NULL** (avisar 1 vez).
- Ajustados 3 casos do `ServersCanalTest` para a nova fonte de cadência (`*_repeat_s`) e o novo
  formato de texto (por-incidente) — mudança legítima desta feature no alertador (o Sender de
  Campanha e o pipeline seguem sem diff).

## Confirmações finais
- **matching/FlowEngine/Sender de Campanha/billing: sem diff.** Produção/Nextgest/nginx/Tunnel
  intocados. Transporte do alerta inalterado (direto).
- Migration aditiva aplicada e confirmada por leitura (`db:table`).
- **`queue:restart`** executado (2026-07-07 14:01:43) — a feature toca o job `SendServerAlert`
  e o `AlertNotifier` (carregados por worker/scheduler; daemons ativos → recicla).
- Suíte **sequencial**: 1068 → 1077 verdes (4163 → 4201 assertions), zero falha.
- Commit `8f1f8da`, **sem push** (remote `msgautomation_server`; o dono empurra).
