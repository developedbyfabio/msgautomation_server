# Servidores — Fatia 2 (S2): regras + avaliação + incidentes (100% MUDO) — 2026-07-07

Git no início: HEAD `b8a25c1` (relatório S1), branch `master`, working tree limpo.
**`APP_ENV=local` confirmado** (instância DEV `192.168.11.210`). Produção `187.127.24.165` /
Nextgest / nginx / Cloudflare Tunnel: **não tocados**.

**Baseline: 975 verdes / 3875 assertions** (confirmado neste HEAD antes de escrever).
**Final: 1012 verdes / 3987 assertions** (+37 testes / +112 assertions — exatamente os três
arquivos novos). Zero regressão.

**Hash do commit da fatia: `e83b06f`** (este relatório entra em commit de docs subsequente).

---

## 1. Migrations (aditivas, confirmadas por leitura no DEV)

`2026_07_07_000002_create_server_alert_rules_and_incidents.php` — duas tabelas novas +
seed dos padrões. Nomes **prefixados** (`server_alert_rules`/`server_incidents`, não
`alert_rules`/`incidents` genéricos — evita colisão com domínio futuro do SaaS; ajuste
deliberado nº 1). Verificadas com `php artisan db:table` após `migrate` (12 colunas nas
regras; seed = 6 regras globais para a conta existente).

**`server_alert_rules`**: `account_id` FK, `server_id` FK **nullable** (NULL = padrão
global da conta; preenchido = sobrescrita do servidor), `metric` (`cpu|ram|swap|disk|load|watchdog`),
`warning_threshold` (nullable), `critical_threshold`, **`warning_for_s`/`critical_for_s`**
(histerese POR NÍVEL — os defaults pedem durações diferentes por nível; ajuste nº 2),
`cooldown_s` (re-notificação, uso real na S3), `enabled`.

**`server_incidents`** (estado DURÁVEL — MySQL é a fonte de verdade): `server_id`,
`rule_id` (nullOnDelete — regra apagada não órfã o histórico), `metric`, `mount`
(disco: **qual** partição), `level` (`warning|critical`), `status`
(`firing|acknowledged|resolved`), **`open_key` nullable UNIQUE**
(`"{server}:{metric}[:{mount}]"` enquanto aberto, NULL no resolve — garante **no banco**
"um ativo por (servidor, métrica/partição)" e decide corridas, mesmo padrão do `event_id`
do billing), `value_at_fire`, `detail` JSON (janela observada/gap), `started_at`,
`acknowledged_at/_by`, `resolved_at`, `notified_firing_at`/`notified_resolved_at` (marcas
da ação de notificação por transição — a S3 as reusa para nunca re-enviar).

**Padrões seedados** (editáveis na tela Alertas):

| métrica | warning | critical | for (warn/crit) | cooldown |
|---|---|---|---|---|
| CPU | 85% | 95% | 5 min / 2 min | 30 min |
| RAM | 85% | 95% | 5 min / 2 min | 30 min |
| Swap | 25% | 50% | 5 min / 5 min | 30 min |
| Disco (por partição) | 85% | 95% | 1 min / 1 min | 60 min |
| Load (por núcleo) | 1.5 | 2.5 | 5 min / 5 min | 30 min |
| Watchdog | 180 s sem reportar | 300 s | — (o gap já é duração) | 30 min |

Seed no `up()` via `DB::table` (sem models — migration congelada no tempo) para as contas
existentes; contas futuras cobertas por `AlertRuleDefaults::ensureFor()` **lazy e
idempotente** (`firstOrCreate`, nunca sobrescreve edição do dono) no `mount()` da tela
Alertas e no início do command.

**Precedência de regra (registrada):** específica do servidor **>** global da conta —
**inclusive `enabled=false`**: sobrescrita desligada **silencia** a métrica naquele
servidor (não cai na global). Regras globais não são removíveis pela UI (só editáveis/
desligadas); sobrescritas podem ser removidas (volta ao padrão). Unicidade
(conta, servidor, métrica) garantida na aplicação (`firstOrCreate`) — o unique do MySQL
não cobre `server_id` NULL (ajuste nº 3).

## 2. Histerese sobre o buffer efêmero (definição registrada)

A avaliação lê as amostras da S1 (`MetricsBuffer`, 60 amostras/TTL 1h, mais recente
primeiro; `t` = `received_at`, relógio do servidor central). **Condição satisfeita** quando,
andando da amostra **mais recente para trás**, todas violam o limiar **consecutivamente**,
com **≥ 3 amostras** (`ServerEvaluator::MIN_SAMPLES`) e **span observado**
(`t_recente − t_antiga da sequência`) **≥ for_duration do nível**. Um pico normal no meio
quebra a sequência (pico transitório não dispara). **Amostras insuficientes** (servidor
novo, flush do Redis) → **não** dispara por métrica — o "sem dado" é papel do watchdog.

**Resolução com a mesma histerese (anti-flapping):** só resolve quando há sequência
**limpa** (abaixo do limiar de warning; do critical se a regra não tem warning) cobrindo
`warning_for_s` (mínimo 60s). **Sem amostras → não resolve**: flush do Redis nunca fecha
incidente (durabilidade).

**Load:** `load1 / cpu_count` comparado aos limiares (1.5/2.5 por núcleo). **Sem
`cpu_count` no payload, a comparação vira absoluta contra o mesmo limiar** — limitação
prevista no prompt, coberta por teste; o agente da S5 sempre enviará `cpu_count`.
**Disco:** cada partição vista na janela é avaliada contra a regra `disk`; o incidente
carrega o `mount`.

## 3. Máquina de estado (durável) — `IncidentManager`

- **ok → firing**: `create` com `open_key`; corrida → `UniqueConstraintViolationException`
  → no-op. **Uma** ação de notificação (`firing`).
- **warning → critical (escalada)**: atualiza o **mesmo** incidente (nunca abre segundo) +
  **uma** ação `escalated`. **Sem downgrade** critical→warning (só resolve).
- **firing → acknowledged**: ack do dono na tela (gate `authorizeOwnerAction`); registra
  `acknowledged_at/_by`; **segue aberto e monitorado** (open_key mantido) — silencia
  repetição; escalada e resolução continuam funcionando.
- **aberto → resolved**: normalização (ou watchdog: voltou a reportar) → `resolved_at`,
  `open_key = NULL` (libera a chave para reincidência FUTURA = novo incidente, histórico
  preservado) + **uma** ação `resolved`.
- **Nada de re-disparo por tick**: estado-alvo igual ao atual = no-op. `cooldown_s` fica
  para a re-notificação opcional da S3 (na S2 não há repetição alguma).

## 4. Watchdog (dead man's switch) com precedência

- Lê **só o `last_seen_at` durável** (MySQL) — imune a flush do Redis. `gap = agora −
  last_seen_at`; `gap ≥ critical_threshold` (300s) → critical; `≥ warning_threshold`
  (180s) → warning; `< warning` → resolve o watchdog aberto.
- **Precedência (registrada):** `gap ≥ warning do watchdog` ⇒ servidor **stale** ⇒ as
  métricas **não avaliam** — dado velho **nem abre nem fecha** incidente de métrica
  (incidente de métrica aberto fica **congelado** até o servidor voltar). Só o watchdog
  transiciona. Coberto por teste (buffer cheio de violação + stale ⇒ só watchdog).
- `last_seen_at` NULL (nunca reportou) → watchdog **não** se aplica ("aguardando primeiro
  contato", coerente com o selo da S1). Servidor `enabled=false` → fora da avaliação inteira.

## 5. Command `servers:evaluate` (idempotente, sem sobreposição)

- `app/Console/Commands/ServersEvaluate.php`; agendado em `routes/console.php`:
  `Schedule::command('servers:evaluate')->everyMinute()->withoutOverlapping()`.
- **Dois níveis de não-sobreposição**: `withoutOverlapping` (mutex do scheduler) **+ lock
  próprio** `Cache::lock('servers:evaluate:lock', 50)` — cobre invocação direta/concorrente;
  sem o lock, o command **pula** ("já em execução") sem avaliar nada (testado).
- **Idempotência por construção**: o avaliador não guarda estado próprio — lê buffer +
  regras + incidentes e **converge**; `open_key` unique + refs únicas de notificação fazem
  a segunda execução ser no-op byte a byte (testado: 2× = mesmos incidentes, mesmos eventos;
  provado também **ao vivo** contra o MySQL/Redis reais — ver §8).
- Cross-account sem sessão: `withoutAccountScope` + `account_id` explícito em tudo que grava
  (mesma disciplina dos webhooks).

## 6. Modo silencioso (a garantia da fatia)

- **Flag**: `config/servers.php` → `notifications_enabled = env('SERVERS_NOTIFICATIONS_ENABLED', false)`
  — **OFF por default** (não está no `.env`; testado que o default é false).
- **`AlertNotifier`** é o único ponto de "notificação": com o flag OFF, cada transição
  (`firing`/`escalated`/`resolved`) grava **um** `SystemEvent` `type=servidores` com ref
  única `srv-incident:{id}:{transição}` e título `"[silencioso] Teria notificado: {servidor}
  — {métrica}({partição}) {nível} (valor X)"`. Níveis: firing critical/escalada → `error`,
  firing warning → `warning`, resolved → `info` — visível na tela **Logs** existente sem
  tocá-la. As marcas `notified_*_at` são gravadas — a S3 as reusa.
- **Branch do flag ON já existe** mas hoje também só registra (com a ressalva "canal não
  implementado — S3"): ligar o flag por engano na S2 **não envia nada**.
- **Prova de mudez** (teste `cem_por_cento_mudo...`): ciclo completo firing → escalada →
  resolved com `Http::fake()` ⇒ `Http::assertNothingSent()` (nenhum HTTP Evolution/Cloud),
  `AutoReplyLog` zerado (Sender nunca tocado) e ≥3 eventos "Teria notificado" nos Logs.
  Além disso, **nenhum arquivo da S2 referencia** `Sender`/`ProviderRegistry`/`Http`
  (o notifier não importa nada de envio, de propósito).

## 7. Telas (Livewire 4 + Flux free, owner-only)

- **Sidebar**: o item solto da S1 virou **grupo "Servidores"** (mesmo mecanismo
  `flux:sidebar.group expandable` da Automação): Inventario (`server-stack`), Alertas
  (`bell-alert`), Incidentes (`exclamation-triangle`) com **badge de incidentes abertos**
  (molde do badge do Kanban). Grupo inteiro some para operador (filtro `AreaAccess::MAP`,
  entradas novas `servidores.alertas`/`servidores.incidentes` = owner).
- **Incidentes** (`/servidores/incidentes`): filtro abertos/todos/resolvidos + por servidor,
  badges de nível (amber/red) e estado (firing=red "disparado", acknowledged=sky
  "reconhecido", resolved=emerald "resolvido"), timestamps `paraExibicao()`, valor
  (watchdog em "Xs sem reportar"), botão **Reconhecer** só em firing (gate no ack).
  Aviso de modo silencioso no info-tip.
- **Alertas** (`/servidores/alertas`): seletor de escopo (padrões globais ↔ sobrescritas de
  um servidor); por métrica mostra a regra **efetiva** + origem (`padrao global` /
  `sobrescrita`); ações Editar / Ligar-Desligar / Sobrescrever (copia a efetiva) / Remover
  sobrescrita (global é irremovível — guard `whereNotNull('server_id')` + teste). Modal com
  unidades por métrica (%, load/núcleo, segundos para watchdog) e validação
  `warning ≤ critical`. Banner explícito do modo silencioso.
- **Painel ao vivo**: continua fatia futura — **nenhum placeholder** necessário (o grupo da
  sidebar não exige).

## 8. Smoke ao vivo (DEV real, além da suíte)

`php artisan servers:evaluate` contra o MySQL/Redis reais: o `srv-smoke-dev` da S1 (último
reporte 02:54) estava mudo há **1982s** ⇒ watchdog abriu **critical firing** com evento
`[error] [silencioso] Teria notificado: srv-smoke-dev — Sem reportar (watchdog) critical
(valor 1982)`. Segunda execução: **1 incidente, 1 evento** (idempotência ao vivo). Nenhum
WhatsApp saiu. O incidente ficou aberto de propósito — visível na tela Incidentes com o
badge "1" na sidebar; resolve sozinho se o curl de ingestão da S1 voltar a rodar (ou pode
ser reconhecido na UI).

**Nota operacional (registrada, não resolvida aqui):** para a avaliação rodar ao vivo
continuamente, o **scheduler precisa estar ativo** no DEV — os units systemd
(`deploy/systemd/msgautomation-scheduler.service`, `schedule:work`) **não estão ativos
nesta máquina** (situação herdada, já registrada na S1). Ativação (passo do dono):
`systemctl enable --now msgautomation-scheduler` (e o worker, quando a S3 for enfileirar).
Os testes chamam o command direto e não dependem do cron.

## 9. Testes (3 arquivos novos, 37 testes / 112 assertions)

**`ServersAvaliacaoTest`** (23): pico curto não dispara; persistência abre critical (com
evento "Teria notificado" nível error); faixa warning; **2 amostras não disparam** (mín. 3);
buffer vazio não dispara; **disco por partição identificando o mount**; **load por núcleo**
(warning com 8 núcleos) e **absoluto sem cpu_count**; um ativo por (servidor, métrica) em
3 execuções; **escalada no mesmo incidente** (id preservado + evento escalated);
normalização resolve com **uma** notificação (re-rodar não duplica o evento);
**incidente sobrevive a `Cache::flush`** (nem fecha nem duplica); **resolvido não
ressuscita** e reincidência abre **novo** (histórico preservado); watchdog warning→critical
pelo gap; **precedência** (stale ⇒ só watchdog, zero incidente de métrica);
**stale congela** incidente de métrica aberto; watchdog resolve ao voltar a reportar;
nunca-reportou não dispara; **sobrescrita vence a global** (2 servidores, só o com override
dispara) e **sobrescrita desligada silencia** (não cai na global); command 2× sem duplicar
nada; **lock impede execução sobreposta** (e libera depois); servidor desativado fora;
**100% mudo** (`Http::assertNothingSent` + zero `AutoReplyLog` + rastro nos Logs); defaults
idempotentes.

**`ServersIncidentesUiTest`** (6): owner 200 / operador 403; grupo do menu some para
operador (Alertas e Incidentes); **ack** marca reconhecido com autor e **mantém aberto**;
ack forjado de operador barrado (403, estado intacto); **reconhecido não reabre** com a
condição persistindo e **resolve pela normalização**; filtro por estado.

**`ServersAlertasUiTest`** (6): owner vê os 6 padrões (mount garante) / operador 403;
editar global persiste; **warning > critical rejeitado**; sobrescrever copia a efetiva e
remover volta ao padrão (globais intactas); **global nunca é removida**; ação forjada de
operador barrada.

## 10. Ajustes deliberados (um a um)

1. **Nomes de tabela prefixados** `server_alert_rules`/`server_incidents` (prompt dizia
   `alert_rules`/`incidents`): evita colisão com domínio futuro; segue a Fase 0.
2. **`for_duration` por nível** (`warning_for_s`/`critical_for_s`): o prompt lista durações
   diferentes por nível nos próprios defaults (CPU warn 5min/crit 2min) — uma coluna única
   não expressaria isso.
3. **Unicidade (conta, servidor, métrica) na aplicação** (não no banco): unique do MySQL
   ignora NULL em `server_id`; `firstOrCreate` cobre.
4. **Mínimo de 3 amostras consecutivas** na histerese (e na limpeza da resolução): amostra
   única/dupla não confirma janela — anti-falso-positivo pós-flush.
5. **Resolução exige sequência limpa por `warning_for_s` (mín. 60s)** — anti-flapping;
   sem amostras não resolve (durabilidade).
6. **Escalada = mesmo incidente, sem downgrade**; escalada sobre acknowledged mantém o
   ack (só atualiza nível + registra).
7. **Watchdog sem histerese própria**: o gap já É uma duração (campos `*_for_s` = 0 e
   ocultos no modal com explicação).
8. **Load absoluto sem `cpu_count`** (limitação prevista no prompt, testada e documentada
   na UI pela unidade "load/núcleo").
9. **Incidente de partição que some das amostras** (desmontada/agente reconfigurado) fica
   aberto até normalizar ou o dono reconhecer — caso raro, sem fechamento automático por
   ausência de dado (coerente com "sem dado não fecha"); registrado como limitação.
10. **Smoke ao vivo deixou 1 incidente watchdog aberto** no DEV (demonstração real na UI;
    resolve sozinho com nova ingestão ou via ack).
11. **Pint** aplicado; **assets rebuildados** (classes novas das views nas telas).

## 11. Confirmações finais

- **Produção/Nextgest/nginx/Tunnel intocados**; trabalho 100% no DEV.
- **Pipeline/matching/FlowEngine/Kanban/billing/sender: zero diff** — arquivos existentes
  tocados (+31/−7): `routes/web.php` (2 rotas no grupo owner), `routes/console.php`
  (1 agendamento), `AreaAccess::MAP` (2 entradas), `app.blade.php` (grupo + badge).
  O serviço de envio **não é referenciado por nenhum arquivo da S2**.
- **Nenhum WhatsApp nesta fatia**: flag OFF por default; provado por teste
  (`Http::assertNothingSent` + zero `AutoReplyLog`) e pelo smoke ao vivo.
- Migrations aditivas aplicadas e confirmadas por leitura; **sem** tabela de histórico de
  métricas (o que persiste de novo é o **incidente**).
- **`php artisan queue:restart` executado** (03:27:41) — command novo carregado por
  scheduler/worker; units não ativos nesta máquina (sinal fica no cache), como na S1.
- Suíte inteira **sequencial**: 975→1012 verdes (3875→3987 assertions), zero falha.
- Commit `e83b06f` (código), **sem push** (repo da instância DEV; Fabio empurra).
