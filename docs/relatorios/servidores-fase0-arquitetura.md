# Servidores — Fase 0: diagnóstico + proposta de arquitetura — 2026-07-07

**Fase somente de leitura.** Nenhuma migration, rota, componente ou código de aplicação foi
criado ou alterado — este documento é o único artefato produzido.

Git no início: HEAD `0f42d16` (fatia 26 — billing Asaas), branch `master`, working tree
**limpo**. Instância confirmada como **DEV**: `APP_ENV=local`, `APP_URL=http://192.168.11.210:8080`
(rede local). A produção `187.127.24.165` / painel.nextgest.com.br **não foi tocada** e não
será por este trabalho. Infra local: MySQL, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`
(predis, porta 6380, prefix `msgauto_`).

Stack: `laravel/framework ^13.8` (bootstrap slim, sem Kernel), PHP `^8.3`,
`livewire/livewire ^4.3`, `livewire/flux ^2.15` (**tier free** — sem `auth.json`, sem repo Pro).

---

## PARTE 1 — INSPEÇÃO (padrões reais encontrados)

### 1.1 Rotas e middleware

- **Só existe `routes/web.php`** (não há `api.php`; `bootstrap/app.php` registra apenas
  `web:` e `commands:`). Webhooks vivem no fim do `web.php`.
- Páginas Livewire são registradas com o componente direto como action:
  `Route::get('/campanhas', Campanhas::class)->name('campanhas')`.
- Gates empilhados por aninhamento de grupos: `['auth','verified','account.operational']`
  é o grupo principal da UI (`routes/web.php:70-150`); dentro dele, sub-grupos
  `account.role:owner` (`:117-122`, `:145-148`) e `whatsapp.connected` (`:133-149`).
- **Webhooks** (todos sem sessão, controllers single-action `__invoke` que só autenticam,
  deduplicam e enfileiram):
  - Evolution/Cloud: `Route::post('/webhook/evolution/{token}', ...)->middleware('webhook.secret')`
    — o middleware `VerifyWebhookSecret` resolve o `Channel` pelo token da URL
    (`withoutAccountScope`) e delega a verificação ao provider (`hash_equals`). Sem token = 401.
  - Asaas (fatia 26): `Route::post('/webhook/asaas', AsaasWebhookController::class)` — token no
    **header** (`asaas-access-token`) comparado com `hash_equals` contra `config('billing.asaas.webhook_token')`
    (`AsaasWebhookController.php:30-35`); idempotência por `event_id` unique + catch de
    `UniqueConstraintViolationException` → 200 no-op; dispatch assíncrono + `200` imediato.
- **CSRF**: `bootstrap/app.php:41-43` isenta o prefixo `webhook/*`
  (`$middleware->validateCsrfTokens(except: ['webhook/*'])`). Qualquer endpoint novo sob
  `/webhook/...` já nasce isento.
- **Rate limiting**: só existem `RateLimiter::for('login')` e `for('two-factor')` no
  `FortifyServiceProvider` (5/min). **Nenhum webhook tem throttle hoje** — o rate limiting da
  ingestão será uma adição nova (padrão já existente para se apoiar: o `Cadastro.php:90-112`
  usa o facade `RateLimiter` imperativamente com múltiplas chaves por e-mail/IP).
- Não há FormRequests; validação leve dentro do controller/componente.

### 1.2 Livewire 4 + Flux (UI)

- Componentes de página **achatados** em `app/Livewire/` (PascalCase pt-BR: `Campanhas.php`,
  `Contatos.php`...), única subpasta `Admin/`. Views em
  `resources/views/livewire/<kebab>.blade.php`. Layout via atributo
  `#[Layout('components.layouts.app')]`; ninguém usa `#[Title]`.
- **Flux é FREE**: o projeto só usa `flux:sidebar*`, `flux:menu*`/`flux:dropdown`,
  `flux:icon`, `flux:tooltip`, `flux:breadcrumbs`, `flux:switch`. **Não existem**
  `flux:table/card/button/modal/input/badge` — tabelas são containers
  `rounded-xl border ... divide-y` com `@forelse`; modais são o componente próprio
  `<x-modal>` (`resources/views/components/modal.blade.php`, "Flux modal e Pro, evitado");
  inputs/botões são Tailwind manual.
- CRUD exemplar: `app/Livewire/Campanhas.php` — flags de modal (`$showForm`, `$editingId`,
  `$confirmingXId`), métodos `novo()/edit()/closeForm()/save()`, toast via
  `$this->dispatch('toast', ...)`, serviços injetados na assinatura dos métodos,
  `render()` com `->get()` (**não há paginação em lugar nenhum do projeto**).
- Gate de escrita **server-side** no topo de toda ação: `AreaAccess::authorizeEditAction('area')`
  / `authorizeOwnerAction()`; flag cosmética `podeEditar` para esconder botões.
- **Badges de status**: `<span>` com `@class([...])` e paleta consistente —
  emerald = ok/ativo, amber = atenção/pausado, red = erro/crítico, zinc = neutro/rascunho,
  sky = info, indigo = concluído (`campanhas.blade.php:25-33`).
- **`wire:poll` já é precedente**: `conexao` e `conversas` usam `.5s`; `kanban` e
  `status-conexao` usam `.15s`. O `Painel` deliberadamente não faz poll (cache 60s + botão).

### 1.3 Sidebar (mecanismo exato)

A navegação é um **array PHP no topo do layout** (`resources/views/components/layouts/app.blade.php:18-40`):
`$navGrupos = [['heading' => 'Automacao', 'items' => [[rota, rótulo, ícone, badge], ...]], ...]`.
Grupo com `heading` → `<flux:sidebar.group expandable>` (é exatamente assim que "Automação"
existe); item → `<flux:sidebar.item :href="route($rota)" wire:navigate :badge="...">`. Item
ativo é detectado pelo próprio Flux via href. Filtro por papel: cada item passa por
`AreaAccess::allows()` usando `AreaAccess::MAP[rota]` (`app.blade.php:44-61`) — item some do
menu, mas **a barreira real é o middleware da rota**. Adicionar a aba Servidores = novo grupo
no array + rotas + entradas no `MAP`.

### 1.4 Multitenancy por Empresa

Mecanismo próprio ("MT"), em `app/Tenancy/`:

- `AccountContext` (singleton; `id()` lança `MissingAccountContextException` sem contexto;
  `runAs()` para comandos/scheduler cross-account).
- `AccountScope` (global scope `where account_id = contexto`).
- Trait `BelongsToAccount` — adiciona o scope e injeta `account_id` no `creating`;
  bypass nomeado `Model::withoutAccountScope()`.
- Contexto resolvido por **sessão + vínculo** (`SetAccountContext` appendado ao grupo web,
  `bootstrap/app.php:38`). Papéis por conta: `owner|operador` no pivô `account_user`;
  `users.is_platform_admin` ortogonal (tela Empresas).

**Avaliação para esta feature:** usar o escopo por Empresa aqui é **mais simples do que
criar exceção**. A trait custa uma coluna `account_id` e um `use`; todo o resto (contexto de
sessão, gates, filtro do menu) já funciona sozinho. Criar models "globais" exigiria justificar
o desvio em cada query e abriria mão de `AreaAccess` de graça. **Recomendação: usar
`BelongsToAccount` normalmente**, com os servidores cadastrados na Empresa do Fabio, e rotas
**owner-only** (ferramenta interna de infra não é para operador). Pergunta em aberto nº 1.

### 1.5 Serviço de envio WhatsApp/Evolution (para reusar como cliente)

Duas camadas — e a distinção importa para alertas:

1. **Transporte puro** — contrato `app/Channels/ChannelProvider.php`:
   `sendText(Channel $channel, string $to, string $text, ?string $replyTo = null): SentMessageData`.
   Implementação `app/Channels/Evolution/EvolutionProvider.php:92` (HTTP `POST
   /message/sendText/{instance}`, header `apikey`, timeout 20s; falha → `WhatsappSendException`;
   **sem retry interno** — resiliência vem do retry da fila do job chamador). Resolvido por
   canal via `app/Channels/ProviderRegistry.php` (`for($channel)`); credenciais vêm de
   `channels.credentials` (cifrado) com fallback no `.env`; **a instância é sempre a do canal**.
   Canal padrão da conta: `Channel::defaultFor(int $accountId)` (`app/Models/Channel.php:40`).
2. **Orquestrador** — `app/Whatsapp/AutoReply/Sender.php:33`: `send(mode, channel, jid, text, ...)`
   com modos `auto|manual|aprovacao|proactive`. Cria `AutoReplyLog`, aplica **AntiBanGuard,
   throttle, janela 24h, rodapé opt-out, vault de segredos** e então chama o transporte.

Como as Campanhas enviam: Livewire aprova e **snapshota** targets → `proactive:tick`
(everyMinute) enfileira `SendProactiveMessage` (`$tries=3`, `$backoff=[30,60]`, fila
`default`) → job faz claim atômico + freios (`ProactiveGuard`) → `Sender->send(mode:'proactive', ...)`.

**Achado que não encaixa 100% (registrado, não improvisado):** o `Sender` embute semântica
de *atendimento/marketing* — anti-ban, opt-out, janela 24h, log em `auto_reply_logs` (a
timeline de conversas). Um alerta de infra crítico **não pode ser silenciosamente bloqueado
por freio anti-ban** nem deve poluir o log de atendimento. Reusar "o sender das Campanhas"
ao pé da letra significa aceitar esses freios. A alternativa fiel ao contrato é reusar o
**transporte** (`ProviderRegistry->for($channel)->sendText(...)`) — o mesmo código que
efetivamente envia pelas Campanhas, uma camada abaixo — com log próprio em `SystemEvent`.
**Recomendação: transporte direto.** Pergunta em aberto nº 5. Em ambos os casos o serviço
existente **não é alterado** — só consumido.

### 1.6 Cofre de credenciais

- Model `Secret` (`account_id, nome, value_encrypted, categoria, notes`; unique
  `(account_id, nome)`; `value_encrypted` em `$hidden`).
- Serviço **`app/Whatsapp/Secrets/SecretVault.php`**: `put(accountId, nome, plain, ?categoria, ?notes)`,
  `reveal(accountId, nome): ?string`, `names()`, `resolve()` (token `{senha:nome}`),
  `redact()/mask()`. Cifra **dedicada** (`SecretCipher`, chave `SECRETS_KEY` separada do
  `APP_KEY`). Revelar na UI exige re-digitar a senha de login (`Senhas::confirmReveal`).
- A fatia 24 renomeou só a fachada ("Cofre de credenciais"); classes/rotas/tabela continuam
  `Senhas`/`secrets` — contrato intacto.

**Implicação para o token do agente:** o Cofre guarda o token em texto cifrado recuperável
(ótimo para o dono copiar na instalação do agente), mas **procurar "qual servidor tem este
token" exigiria decifrar um a um**. A ingestão precisa de lookup O(1); a solução padrão é
guardar também um **hash SHA-256 do token** na tabela de servidores (coluna indexada) — o
Cofre continua sendo a fonte recuperável, o hash é só o índice de autenticação. Nenhum token
em texto plano fora do Cofre.

### 1.7 Logs

- Model `SystemEvent` (`account_id` **nullable** = evento global; `channel_id`, `type` (32),
  `level` (`info|warning|error`), `title` (200), `detail` **JSON** (cast array), `ref` (120,
  **unique** — idempotência), `occurred_at` indexado).
- Gravação: `SystemEvent::global($level, $title)` (best-effort, nunca lança) ou
  `SystemEvent::withoutAccountScope()->create([...])` com conta (exemplos:
  `BillingState.php:75`, `ProcessIncomingWhatsappMessage.php:205` com `firstOrCreate` por `ref`).
- Tela `/logs` (`app/Livewire/Logs.php`, owner-only) agrega fontes com filtro por `tipo` —
  basta um `type` novo (ex.: `servidores`) para os eventos aparecerem lá.
- Retenção: molde `unmatched:prune` agendado (`routes/console.php:19`).

**Nota de desenho:** registrar **cada ingestão** em `system_events` geraria 2.880–5.760
eventos/dia **por servidor** — inviável. Proposta: registrar apenas **anomalias** (falha de
auth, payload rejeitado, rate limit) e **transições de incidente** (firing/resolved/ack).
Pergunta em aberto nº 9.

### 1.8 Fila e schedule

- Fila: conexão `redis`, **uma única fila `default`**, sem Horizon. Worker é **systemd**:
  `deploy/systemd/msgautomation-worker.service:13` →
  `queue:work --queue=default --sleep=1 --tries=3 --timeout=60 --max-time=3600`.
  Nenhum job declara `onQueue()` hoje. **Fila nomeada nova só é consumida se o `ExecStart`
  do unit for alterado** (ex.: `--queue=alerts,default` — o Laravel drena na ordem listada,
  o que já dá prioridade).
- Schedule: `routes/console.php` com a facade `Schedule` (precedente de minuto em minuto:
  `proactive:tick`, `:16`). Scheduler é systemd rodando **`schedule:work`**
  (`deploy/systemd/msgautomation-scheduler.service`) — processo persistente, suporta
  sub-minuto se necessário.

### 1.9 Colisão de nome

`app/Metrics/PainelMetrics.php` já existe e é outra coisa (M-1 — agregados do painel de
*atendimento*, leitura pura com cache 60s). A nova feature **não** deve usar o namespace
`App\Metrics`. Proposta: **`App\Servers`** (serviços/domínio) + **`App\Livewire\Servidores`**
(páginas), seguindo o padrão do projeto (domínio em inglês: `Billing`, `Kanban`, `Tenancy`;
Livewire em português).

---

## PARTE 2 — PROPOSTA DE ARQUITETURA

### 2.1 Schema mínimo (4 tabelas — nenhuma de histórico de métricas)

Todas com `use BelongsToAccount` + FK `account_id constrained()->cascadeOnDelete()`
(condicional à pergunta nº 1), migrations aditivas com docblock e `down()` minimalista,
status como **string + const de array no model** (padrão dominante do projeto).

**`monitored_servers`** — inventário:

| coluna | tipo | nota |
|---|---|---|
| `account_id` | FK | escopo Empresa |
| `name` | string | rótulo ("Servidor ERP") |
| `slug` | string, unique/conta | usado no nome do segredo no Cofre |
| `hostname` | string nullable | descritivo |
| `os` | string | `linux\|windows` |
| `environment` | string | `producao\|teste\|dev` — badge na UI e defaults de regra |
| `agent_token_hash` | string(64), **unique** | SHA-256 do token; lookup O(1) na ingestão |
| `enabled` | bool default true | desligado = não avalia, não alerta |
| `maintenance_until` | timestamp nullable | **janela de manutenção** (silencia avaliação) |
| `last_seen_at` | timestamp nullable | atualizado a cada ingestão — base do watchdog |
| `last_sample` | json nullable | última amostra (cards do Painel sobrevivem a flush do Redis) |
| `notes` | text nullable | |

`last_seen_at`/`last_sample` **não são histórico** — são estado corrente (1 UPDATE por
ingestão a cada 15–60s por servidor; com ~10 servidores, desprezível). Manter no MySQL torna
o watchdog imune a flush/restart do Redis. Alternativa 100% cache descrita em 2.2.

**`server_alert_rules`** — regras/limiares:

| coluna | tipo | nota |
|---|---|---|
| `account_id` | FK | |
| `server_id` | FK nullable | **null = regra padrão para todos**; regra específica sobrepõe |
| `metric` | string | `cpu\|ram\|swap\|disk\|load\|watchdog` |
| `mount` | string nullable | só p/ `disk`; null = pior partição reportada |
| `warning_threshold` | decimal nullable | % (cpu/ram/swap/disk), load1/núcleo (load), — (watchdog) |
| `critical_threshold` | decimal | watchdog: **segundos sem reportar** |
| `for_duration_s` | int default 120 | histerese ("por N segundos") |
| `cooldown_s` | int default 1800 | intervalo mínimo entre re-notificações do MESMO incidente |
| `enabled` | bool | |

Defaults seedados na criação do servidor (editáveis): CPU 80/90% por 120s; RAM 90/95%;
swap 50/80%; disco 85/95%; load1/núcleo 2.0/4.0; watchdog 180s (Linux) / 300s (Windows,
cadência maior — ver 2.5).

**`server_incidents`** — máquina de estado (isto **é** persistido; incidente não é métrica):

| coluna | tipo | nota |
|---|---|---|
| `account_id`, `server_id`, `rule_id` | FKs | `rule_id` nullable (regra apagada não órfã o histórico) |
| `metric`, `mount` | string | denormalizado p/ exibição |
| `level` | string | `warning\|critical` (pode escalar) |
| `status` | string | `firing\|acknowledged\|resolved` |
| `open_key` | string **nullable unique** | `"{server}:{metric}:{mount}"` enquanto aberto; **NULL ao resolver** (MySQL permite N nulls) — garante no banco que só existe 1 incidente aberto por servidor+métrica e dá idempotência à avaliação (mesmo padrão do `event_id` unique do billing) |
| `value_at_fire` | decimal nullable | valor que disparou |
| `fired_at`, `acknowledged_at`, `resolved_at` | timestamps | `acknowledged_by` FK users nullable |
| `notified_firing_at`, `notified_resolved_at`, `last_notified_at` | timestamps nullable | flags de idempotência de notificação + base do cooldown |
| `detail` | json nullable | amostras da janela no momento do disparo (diagnóstico) |

**`server_alert_contacts`** — roteamento de notificação:

| coluna | tipo | nota |
|---|---|---|
| `account_id` | FK | |
| `name` | string | "Fabio (celular)" |
| `phone` | string | destino WhatsApp |
| `min_level` | string | `warning\|critical` — quem só quer crítico |
| `enabled` | bool | |

v1: lista global de destinatários por nível. Roteamento por servidor/grupo fica para depois
se precisar.

### 2.2 Buffer recente — onde vive

**Recomendação: Redis** (já é cache e fila do projeto; `predis ^3.5`).

- Por servidor: `LPUSH srv:{id}:samples <json>` + `LTRIM 0,59` + `EXPIRE 3600` a cada
  ingestão. 60 amostras ≈ 15–30 min a 15–30s de cadência — suficiente para qualquer
  `for_duration` razoável. Autolimpante, zero migration, zero prune.
- Estado corrente (`last_seen_at` + última amostra) duplicado nas colunas de
  `monitored_servers` (2.1) — watchdog e Painel não dependem do Redis existir.
- **Trade-off vs tabela volátil MySQL:** tabela sobreviveria a restart do Redis e seria
  consultável em SQL, mas é um mini-TSDB disfarçado (INSERT contínuo + prune agendado +
  crescimento) — exatamente o que a decisão de arquitetura proíbe. Redis perde o buffer num
  flush; consequência real: a histerese recomeça a contar (atraso de N minutos num alerta,
  não alarme falso — o watchdog não é afetado por causa do `last_seen_at` no MySQL). Aceitável.
- **Gráfico 24h (opcional, v2):** segunda lista Redis com 1 ponto/minuto (média),
  `LTRIM 0,1439` — continua fora do MySQL, respeita o teto de 24h. Não entra na v1.

Pergunta em aberto nº 2.

### 2.3 Endpoint de ingestão

- **Rota:** `POST /webhook/servers/ingest` em `routes/web.php`, junto dos demais webhooks —
  o prefixo `webhook/*` herda a isenção de CSRF automaticamente. Controller single-action
  `app/Http/Controllers/ServerIngestController.php` (padrão Asaas: single-action, autentica,
  grava o mínimo, responde).
- **Auth:** token no header `X-Agent-Token` (não na URL — token em URL vaza em access log).
  Lookup: `hash('sha256', $token)` → busca indexada em `monitored_servers.agent_token_hash`
  via `withoutAccountScope()` (mesma técnica do `VerifyWebhookSecret` com `webhook_token`).
  **O token resolve o servidor** — um agente jamais alimenta métrica de outro; o servidor
  carrega o `account_id`, então o isolamento por Empresa vem junto. Token de servidor
  `enabled=false` → 403. Geração/rotação: ver 2.6 (CRUD).
- **Rate limiting (novo padrão, justificado):** `RateLimiter::for('server-ingest', ...)` em
  provider (molde do FortifyServiceProvider) aplicado como `throttle:server-ingest` na rota —
  **10/min por token** (cadência legítima é 2–4/min; folga para clock skew) e **30/min por
  IP** (vários agentes atrás do mesmo NAT). Estouro → 429 barato, sem tocar banco.
- **Validação barata, rejeição rápida (nesta ordem):**
  1. `strlen($request->getContent()) > 16 KB` → 413 (payload legítimo tem ~1 KB);
  2. token ausente/inválido → 401 (uma query indexada; opcionalmente memoizado 60s no cache);
  3. `validate()` estrutural mínimo: campos numéricos, `disks` array com no máx. 20 itens,
     strings truncadas. Malformado → 422 sem side effects.
- **Escrita mínima e resposta imediata:** `LPUSH`+`LTRIM`+`EXPIRE` no Redis + 1 UPDATE
  (`last_seen_at`, `last_sample`) → `204`. **Nenhuma avaliação, nenhum envio, nenhum job**
  no ciclo do request — mais leve que os webhooks existentes (que enfileiram job).
- **Log:** sucesso NÃO gera `SystemEvent` (ver 1.7). Falha de auth e payload rejeitado geram
  `SystemEvent` `type=servidores, level=warning` **com cooldown por IP/token** (cache 10 min)
  para o próprio log não virar vetor de flood.

### 2.4 Command agendado de avaliação

`servers:evaluate` em `app/Console/Commands/`, agendado em `routes/console.php`:
`Schedule::command('servers:evaluate')->everyMinute()->withoutOverlapping()` (60s está na
janela pedida de 30–60s; o `schedule:work` via systemd suporta `everyThirtySeconds()` se
depois quisermos apertar — começar com 1 min).

Por servidor `enabled`:

1. **Janela de manutenção:** `maintenance_until > now()` → pula avaliação inteira; incidentes
   abertos ficam congelados (não notifica nem resolve durante a janela).
2. **Watchdog (primeira classe):** `now() - last_seen_at > threshold` da regra `watchdog` →
   condição crítica "servidor mudo". Como `last_seen_at` vive no MySQL, o watchdog **não
   depende de histórico nem do Redis**. Servidor recém-criado sem `last_seen_at` nunca dispara
   (estado "aguardando primeiro contato" na UI). Voltou a reportar → resolve.
3. **Histerese sem estado extra:** para cada regra, lê as amostras do buffer Redis e exige
   que **todas as amostras dentro de `for_duration_s`** violem o limiar, com **mínimo de 3
   amostras** na janela (evita disparo por amostra única após flush). A histerese é derivada
   do buffer — não existe estado "pending" persistido, o que torna a avaliação **naturalmente
   idempotente**: rodar duas vezes lê o mesmo buffer e chega à mesma conclusão.
4. **Máquina de estado** (contra `server_incidents.open_key`):
   - *ok → firing:* `create` com `open_key` preenchido. Corrida/duplo tick → violação de
     unique → catch → no-op (padrão billing). Marca `notified_firing_at` **antes** de
     despachar o job de notificação — re-execução vê a flag e não re-enfileira. **Uma**
     notificação.
   - *firing (persiste):* nada. Re-notificação opcional só se `cooldown_s` decorrido desde
     `last_notified_at` **e** a regra pedir (default: sem repetição).
   - *warning → critical (escalada):* atualiza `level` + **uma** notificação de escalada
     (sujeita a cooldown).
   - *acknowledged:* dono deu ack na UI — silencia re-notificações; resolução ainda notifica.
   - *firing/acknowledged → resolved:* condição normalizada (ou watchdog voltou) →
     `resolved_at`, `open_key = NULL`, **uma** notificação de "resolvido"
     (guardada por `notified_resolved_at`).
5. **Disparo:** job `SendServerAlert` (fila — 2.7) que resolve os `server_alert_contacts`
   pelo `min_level` e envia via transporte (1.5), uma mensagem por contato, `$tries=3`,
   `$backoff=[30,60]` (molde `SendProactiveMessage`). Falha final de envio → `SystemEvent`
   `level=error` (o alerta que não saiu precisa ficar visível em algum lugar).
6. **Log:** cada transição gera `SystemEvent` (`type=servidores`) com `ref` idempotente
   (ex.: `srv-incident-42-fired`) — aparece na tela Logs existente sem tocá-la.

Contexto de conta: o command roda fora de request → usa `AccountContext::runAs()` por conta
com servidores (mesmo padrão dos comandos existentes).

### 2.5 Agente coletor

**Contexto crítico:** vai rodar em máquinas de produção reais. Requisitos inegociáveis:
read-only no SO, sem porta de escuta (só PUSH de saída), privilégio mínimo, leve, backoff,
remoção trivial.

**Opção A — Telegraf** (agente pronto, InfluxData):
- Prós: maduro, cross-platform, inputs `cpu/mem/swap/disk/system` nativos, output HTTP
  configurável, buffering embutido.
- Contras: **dependência externa instalada em produção** (binário ~200 MB, processo residente
  ~30–80 MB RAM, superfície de configuração enorme para usar 5% dela); o payload JSON é o
  schema do Telegraf (ou o endpoint se adapta a ele, ou mantém-se um template de
  transformação no host); o buffering embutido (que não queremos — acumular é anti-requisito)
  precisa ser explicitamente limitado; atualização de versão vira manutenção nos hosts.

**Opção B — agente próprio, script + timer (recomendada):**
- **Linux:** script shell/POSIX (~80 linhas) lendo `/proc/stat`, `/proc/meminfo`,
  `/proc/loadavg`, `df -P` + `curl --max-time 5` POST. Disparado por **systemd timer** a cada
  15–30s rodando como **usuário dedicado sem privilégio** (`/proc` e `df` não exigem root).
  **Sem processo residente**: executa, envia, morre. Saída vai para o journald (rotação
  nativa — o agente não escreve nenhum arquivo no host). Falha de rede = amostra descartada
  (sem acúmulo — watchdog do servidor central é quem detecta o gap; esse é o backoff mais
  seguro possível para produção: não guardar nada, não re-tentar, esperar o próximo tick).
  Desinstalar = `systemctl disable --now` + apagar 2 arquivos de unit + 1 script. Zero resíduo.
- **Windows:** PowerShell (`Get-CimInstance`/`Get-Counter`) + **Scheduled Task** com usuário
  de serviço sem admin, cadência **60s** (granularidade nativa do Task Scheduler sem truques;
  aceitável — o watchdog do Windows usa threshold maior, 300s). `Invoke-RestMethod` com timeout.
  Sem load average no Windows → campo `load: null` e regras de load ignoram.
- Prós: footprint desprezível, 100% controlado e auditável (o dono lê o script inteiro),
  payload exatamente o do endpoint, remoção trivial, nenhuma dependência de terceiros em
  produção. Contras: 2 scripts para manter (Linux + Windows), casos de borda de parsing são
  nossos.

**Trade-off resumido:** Telegraf compra robustez que este caso não precisa (buffering,
dezenas de plugins) ao custo de uma dependência pesada dentro de máquinas de produção;
o agente próprio é o inverso. Para 5 métricas + push simples + "não acumular jamais",
**recomendo o agente próprio**. **Decisão final do dono** — pergunta em aberto nº 8.

**Payload (JSON, ~1 KB):**

```json
{
  "agent_version": "1",
  "ts": 1751889600,
  "cpu_pct": 37.2,
  "cpu_count": 8,
  "load": [1.2, 0.9, 0.7],
  "mem":  {"total_mb": 16000, "used_mb": 9200, "pct": 57.5},
  "swap": {"total_mb": 4096,  "used_mb": 120,  "pct": 2.9},
  "disks": [{"mount": "/", "total_gb": 100, "used_gb": 63, "pct": 63.0}]
}
```

Identidade vem **só do token no header** (nunca de hostname declarado). HTTPS obrigatório
quando o tráfego sair da LAN — ver pergunta nº 7 sobre o caminho de rede até a instância DEV.

### 2.6 Telas (Livewire 4 + Flux free, padrão real)

Sidebar: novo grupo no `$navGrupos` — `['heading' => 'Servidores', 'items' => [...]]` —
vira `flux:sidebar.group expandable` automaticamente (mecanismo idêntico ao da Automação).
Badge do grupo: contagem de incidentes abertos (molde do badge do Kanban). Rotas sob
`['auth','verified','account.operational','account.role:owner']` + entradas
`servidores.* => 'owner'` no `AreaAccess::MAP`. Ícones free: `server-stack`, `server`,
`bell-alert`, `exclamation-triangle`.

| Página | Rota | Componente | Conteúdo |
|---|---|---|---|
| **Painel** | `/servidores` | `App\Livewire\Servidores\Painel` | grid de cards (molde `painel.blade.php:47-63`): nome, badge de status (emerald=ok, amber=warning, red=critical/mudo, zinc=aguardando/manutenção), CPU/RAM/disco/load da `last_sample`, "visto há Xs". **`wire:poll.15s`** (precedente Kanban/StatusConexao) — lê `monitored_servers` + incidentes abertos, zero Redis na renderização |
| **Servidores** | `/servidores/inventario` | `...\Inventario` | lista `rounded-xl border divide-y` + `<x-modal>` de CRUD (molde Campanhas). Ao criar: gera token (`Str::random(48)`), grava `sha256` na coluna, `SecretVault->put($account, "agente-{slug}", $token, 'servidores')` e **exibe uma única vez** no modal; depois, recuperável só pelo Cofre (re-auth). Ações: rotacionar token, ligar/desligar, janela de manutenção (+30min/+2h/+24h) |
| **Alertas** | `/servidores/alertas` | `...\Alertas` | regras padrão (server_id null) + sobrescritas por servidor; form com warning/critical/for-duration/cooldown |
| **Incidentes** | `/servidores/incidentes` | `...\Incidentes` | linhas com badge de status, métrica, valor, timestamps; botão **Reconhecer** (`authorizeOwnerAction`); filtro por servidor/status; `wire:poll.15s` |

Sem paginação (padrão do projeto — listas pequenas; incidentes com "carregar mais"
incremental como no Logs se crescer).

### 2.7 Prioridade de fila para alertas

**Proposta: fila nomeada `alerts`, worker passa a `--queue=alerts,default`.**

- Justificativa: o `queue:work` drena as filas **na ordem listada** — todo job em `alerts`
  fura a fila de qualquer campanha/mídia/IA pendente em `default`, sem segundo worker, sem
  Horizon, sem nova infra. Cenário real que isso resolve: campanha aprovada enfileira dezenas
  de envios; um servidor de produção cai no meio; o alerta não pode esperar a campanha drenar.
- Custo: **uma linha** no `deploy/systemd/msgautomation-worker.service` + `daemon-reload` +
  restart do unit (+ `queue:restart`) — passo humano documentado na fase de implementação,
  fora do escopo desta fase.
- Fallback aceitável: se o dono preferir não tocar o unit agora, os jobs de alerta nascem em
  `default` (funciona; risco = latência atrás de rajadas) e a fila dedicada vira melhoria
  posterior — o job já nasceria com `onQueue(config('servers.alert_queue'))` para a troca ser
  só de config. Pergunta em aberto nº 3.

---

## PARTE 3 — PLANO DE IMPLEMENTAÇÃO EM FATIAS

| Fatia | Entrega | Critério de aceite |
|---|---|---|
| **S1 — Inventário + ingestão** | migrations (4 tabelas), models, CRUD Servidores (token → Cofre + hash), endpoint `/webhook/servers/ingest` (auth+throttle+validação+buffer Redis+last_seen), rotas + grupo na sidebar + `AreaAccess::MAP`, `SystemEvent` de anomalias | `curl` simulando agente popula buffer e `last_seen_at`; token errado = 401; flood = 429; testes de isolamento entre tokens |
| **S2 — Avaliação + incidentes (modo silencioso)** | `servers:evaluate` agendado, regras CRUD (Alertas), máquina de estado completa, watchdog, histerese, janela de manutenção — transições logadas em `SystemEvent`, **sem envio WhatsApp ainda** (calibrar limiares sem risco de spam) | rodar o command 2× seguidas não duplica incidente/log; derrubar um agente de teste abre incidente watchdog; normalizar resolve |
| **S3 — Notificação WhatsApp** | job `SendServerAlert`, contatos (roteamento por nível), cooldown, fila `alerts` (ou `default` conforme decisão), texto da mensagem (firing/escalada/resolvido) | 1 mensagem por transição por contato; ack silencia; falha de envio vira `SystemEvent error` |
| **S4 — Painel ao vivo + Incidentes UI** | cards com `wire:poll.15s`, tela Incidentes com ack/filtros, badge de incidentes abertos na sidebar | status muda de cor sem refresh manual; ack funciona com gate owner |
| **S5 — Agente coletor + rollout** | script Linux (systemd timer) + Windows (Scheduled Task), doc de instalação/remoção/segurança, instalação **primeiro em máquina de dev/teste**, só depois produção (decisão nº 4) | agente roda sem root, sem porta aberta, sem arquivo além dos units; desinstalação sem resíduo; watchdog detecta agente parado |

Cada fatia é aditiva e independente do pipeline de atendimento/matching/FlowEngine/Kanban/
billing — o único ponto de contato com código existente é: 1 grupo no array da sidebar,
rotas novas em `web.php`, entradas novas no `AreaAccess::MAP`, 1 linha no `routes/console.php`,
1 limiter novo em provider, e (se aprovado) a linha do worker no systemd. O serviço de envio,
o Cofre e os Logs são consumidos **como clientes**, sem alteração.

---

## PARTE 4 — PERGUNTAS EM ABERTO (responder antes da S1)

1. **Escopo por Empresa** — Recomendo usar `BelongsToAccount` normalmente (custo ~zero,
   ganha contexto/gates/menu de graça), com tudo cadastrado na sua Empresa e páginas
   **owner-only**. Confirma? Em qual Empresa (a principal do seu usuário)?
2. **Buffer** — Recomendo Redis (listas com LTRIM/EXPIRE) + estado corrente
   (`last_seen_at`/`last_sample`) em colunas do inventário. Confirma? (Alternativa: tudo em
   cache, mas watchdog ficaria vulnerável a flush do Redis.)
3. **Fila** — Recomendo fila `alerts` com worker `--queue=alerts,default` (1 linha no unit
   systemd, passo manual seu). Ou começar em `default` e migrar depois via config?
4. **Ordem dos servidores** — Quais máquinas entram primeiro? Sugiro 1 Linux de dev/teste na
   S1–S2 para calibrar, produção só na S5. Quantos servidores no total e qual a proporção
   Linux/Windows? (Dimensiona rate limit e prioriza qual agente sai primeiro.)
5. **Sender vs transporte direto** — O `Sender` das Campanhas embute anti-ban/opt-out/janela
   24h e loga na timeline de atendimento; um freio desses pode **segurar um alerta crítico**.
   Recomendo enviar pelo transporte (`ProviderRegistry->sendText`, o mesmo que as Campanhas
   usam por baixo) com log próprio em `SystemEvent`. Confirma, ou prefere passar pelo
   `Sender` com os freios?
6. **Canal e destinatários** — Alertas saem pelo canal padrão da Empresa
   (`Channel::defaultFor`) ou por uma instância Evolution dedicada? Quais números recebem
   (e algum só para `critical`)?
7. **Caminho de rede** — Servidores monitorados fora da LAN alcançam a instância DEV
   (`192.168.11.210:8080`) como? VPN, túnel próprio novo (nunca o da produção Nextgest), ou
   nesta fase só máquinas da LAN? Se sair da LAN, precisa de HTTPS na frente do DEV.
8. **Telegraf vs agente próprio** — Recomendo agente próprio (script + systemd timer /
   Scheduled Task): footprint desprezível, sem dependência externa em produção, remoção
   trivial, sem acúmulo por construção. Telegraf fica como plano B se você preferir agente
   mantido por terceiros. Qual escolhe?
9. **Logs de ingestão** — O pedido original menciona "registrar ingestões" nos Logs;
   registrar cada POST geraria milhares de eventos/dia por servidor. Proponho logar só
   anomalias (auth falha, payload inválido, rate limit) + transições de incidente. Confirma?
