# Servidores — Fatia 1 (S1): ingestão + inventário + token do agente — 2026-07-07

Git no início: HEAD `0f42d16` (fatia 26), branch `master`, working tree limpo (exceto o doc
da Fase 0, untracked, incluído no commit desta fatia). **`APP_ENV=local` confirmado**
(`APP_URL=http://192.168.11.210:8080` — instância DEV). Produção `187.127.24.165` /
Nextgest / nginx / Cloudflare Tunnel: **não tocados**.

**Baseline de testes do DEV: 949 verdes / 3775 assertions** (medido neste HEAD, nesta
máquina — difere dos 918 do relatório da fatia 26, que veio de outra instância).
**Final: 975 verdes / 3875 assertions** (+26 testes / +100 assertions — exatamente os dois
arquivos novos). Zero regressão.

**Hash do commit da fatia: `4b94858`** (código + doc Fase 0; este relatório entra em commit
de docs subsequente, para o hash real constar aqui).

---

## 0. Reparo do ambiente DEV (pré-requisito, registrado)

O DEV tinha o código no HEAD via git, mas **dependências e banco atrasados** — a suíte nem
subia (`Class "Laravel\Fortify\Features" not found`):

- `composer install` (versões travadas pelo lock; `vendor/` estava de 29/jun, sem fortify).
- `npm install` + `npm run build` (o `mermaid` do package.json faltava no node_modules; o
  build de assets estava quebrado antes desta fatia — a view nova usa a classe `break-all`,
  inexistente no CSS antigo, então o rebuild era necessário de qualquer forma).
- **15 migrations pendentes de fatias anteriores** (2FA, system_events, mídia, platform
  admin, operation_mode, kanban, fatia 25/26) aplicadas junto com a desta fatia via
  `php artisan migrate` — **todas aditivas** por política do repo, forward, foreground.
  Nenhum processo dependia do estado antigo (nada escutando na 8080; units
  msgautomation-worker/scheduler não estão ativos nesta máquina).

## 1. Padrões reusados (confirmados no código antes de escrever)

- **Webhook + CSRF**: prefixo `webhook/*` isento (`bootstrap/app.php:41-43`); controller
  single-action que autentica e responde rápido (molde `AsaasWebhookController`).
- **`hash_equals`**: molde do Asaas (`AsaasWebhookController.php:33`) e do
  `VerifyWebhookSecret` (lookup por token de entidade com `withoutAccountScope`).
- **SecretVault** (`app/Whatsapp/Secrets/SecretVault.php`): `put()` cifra com chave dedicada
  `SECRETS_KEY`; `reveal()` decifra em memória; revelar na UI exige re-senha de login
  (padrão `Senhas::confirmReveal`) — reusado **como cliente**, sem alteração.
- **SystemEvent** (`app/Models/SystemEvent.php`): `type/level/detail` JSON + `ref` unique
  (idempotência); gravação best-effort via `withoutAccountScope()->firstOrCreate` (molde
  `ProcessIncomingWhatsappMessage.php:205`).
- **Rate limiting**: `RateLimiter::for(...)` em provider (molde `FortifyServiceProvider`,
  login 5/min) — nenhum webhook tinha throttle; este é o primeiro (adição consciente).
- **Sidebar/AreaAccess**: item novo no array `$navGrupos` (`app.blade.php`) + entrada
  `'servidores' => 'owner'` no `AreaAccess::MAP` + rota com `account.role:owner` — mesmo
  mecanismo de Cofre/Logs; a ocultação do menu é cosmética, a barreira é o middleware.
- **Tenancy**: `BelongsToAccount` no model (decisão da Fase 0: reusar o escopo é mais
  simples que criar exceção mono-dono); área inteira **owner-only**.
- **Redis**: cache do projeto (`CACHE_STORE=redis`, predis, porta 6380, prefixo `msgauto_`).

## 2. Schema e buffer

**Migration `2026_07_07_000001_create_servers_table.php`** (aditiva; confirmada por leitura
com `php artisan db:table servers` após migrar — 13 colunas, uniques em `(account_id,name)`
e `agent_token_hash`, FK cascade):

```php
$table->foreignId('account_id')->constrained()->cascadeOnDelete();
$table->string('name', 100);                       // unique por conta
$table->string('host', 150)->nullable();           // descritivo
$table->string('os', 20)->default('linux');        // v1: linux
$table->string('grupo', 60)->nullable();
$table->string('agent_token_secret_ref', 120)->nullable(); // NOME do segredo no Cofre
$table->string('agent_token_hash', 64)->nullable()->unique(); // sha256 (lookup O(1))
$table->boolean('enabled')->default(true);
$table->timestamp('last_seen_at')->nullable();     // base DURAVEL do watchdog (S2)
$table->json('last_sample')->nullable();           // estado corrente, NAO historico
```

**Buffer recente (`App\Servers\MetricsBuffer`)** — chave `servers:buffer:{id}`, janela de
**60 amostras** (`array_slice` = trim), **TTL 3600s**. Implementado sobre o **facade
`Cache`** (não LPUSH/LTRIM cru no facade Redis), decisão deliberada:

1. a suíte roda com `CACHE_STORE=array` e **não sobrepõe `REDIS_*`** — Redis cru nos testes
   contaminaria o Redis real da instância DEV;
2. cada servidor tem **um** agente (escritor único por chave): read-modify-write é seguro,
   atomicidade de lista não faz falta.

Em produção **é Redis de verdade**: smoke confirmou a chave
`msgauto_msgautomation-cache-servers:buffer:1` no db 1 do Redis com **TTL ativo (3572s)**.
`last_seen_at`/`last_sample` são atualizados a cada ingestão válida no MySQL (com
`timestamps = false` — `updated_at` continua significando "config mudou") — o watchdog da
S2 **não** depende do Redis existir. **Nenhuma tabela de histórico** (guard-rail testado:
3 ingestões não criam linha além do próprio servidor).

## 3. Endpoint de ingestão — `POST /webhook/servers/ingest`

`ServerIngestController` (single-action), na ordem:

1. **413** se o corpo passar de **16 KB** (payload legítimo tem ~1 KB) — antes de qualquer
   parse/query.
2. **Throttle `server-ingest`** (middleware, antes do controller): **10/min por token**
   (chave = sha256 do token, nunca o claro) e **30/min por IP** → 429. Registrado no
   `AppServiceProvider::boot()`.
3. **Auth**: header **`X-Agent-Token`** → `AgentToken::resolve()` = lookup indexado pelo
   sha256 (`withoutAccountScope`, como o `VerifyWebhookSecret`) + **`hash_equals`**
   (timing-safe). Ausente/errado → **401 sem gravar nada**. Servidor `enabled=false` →
   **403** (token válido, ingestão desligada na UI).
4. **Validação mínima** (`cpu_pct`, `mem.pct`, `disks[].mount/pct` obrigatórios; `load`,
   `swap`, `cpu_count`, totais opcionais; máx. 20 partições) → **422 sem efeito colateral**.
5. **Grava o mínimo e responde**: amostra **normalizada** (só campos conhecidos) no buffer +
   `last_seen_at`/`last_sample` no MySQL → `200 {"received":true}`. **Nenhuma avaliação,
   nenhum envio, nenhum job** — fronteira S1 respeitada.
6. **Log `SystemEvent`** (`type=servidores`, best-effort, ref unique): ingestão OK = info
   **1/hora por servidor** (`srv-ingest:{id}:{YmdH}`); auth falha = warning **1/hora por IP**
   (`srv-ingest-auth:{sha1(ip)}:{YmdH}`); payload inválido = warning 1/hora por servidor.
   **O token jamais aparece em log** (testado). Aparece na tela `/logs` existente sem tocá-la.

**Isolamento**: o token resolve **o servidor**; a conta vem do próprio servidor. Token de A
jamais escreve em B (testado).

## 4. Fluxo do token

- **Criação** do servidor → `AgentToken::issue()`: `agt_` + `Str::random(48)`;
  `SecretVault::put(account, "agente-servidor-{id}", token, 'servidores')` (cifra dedicada);
  tabela guarda **só** `agent_token_secret_ref` (nome do segredo) + `agent_token_hash`
  (sha256). **Nunca o claro na tabela** (testado byte a byte).
- **Exibição única**: modal pós-criação/regeneração com o claro (`select-all`); fechar
  descarta do estado do componente. Depois: só revelando no **Cofre** (re-senha de login,
  padrão existente) ou regenerando.
- **Regenerar**: `put()` no mesmo nome do Cofre substitui o valor + hash novo → o token
  antigo passa a dar **401 imediatamente** (testado fim a fim).
- **Excluir servidor**: remove também o segredo do Cofre (sem token órfão) e o buffer.

## 5. Telas

- **Sidebar**: item top-level "Servidores" (`server-stack`) no primeiro grupo, ao lado de
  Campanhas — some para operador via filtro do `AreaAccess::MAP` (owner-only).
- **Rota** `/servidores` → `App\Livewire\Servidores\Inventario`, no grupo
  `auth+verified+account.operational` com `account.role:owner`, **fora** do gate
  `whatsapp.connected` (monitorar servidor não depende do canal).
- **CRUD** no padrão Campanhas/Cofre: lista `rounded-xl border divide-y`, dropdown de ações
  (Editar / Regenerar token / Desativar ingestão / Excluir), modais `<x-modal>` (form,
  token-única-vez, confirmação de regeneração e de exclusão), toasts, gates
  `authorizeOwnerAction()` no topo de **toda** ação de escrita (ação Livewire é forjável).
- **Selo "recebendo dados?"** derivado **só** do `last_seen_at` (zero lógica de alerta):
  `emerald` "Recebendo dados" (< 90s = 3× a cadência de 30s), `amber` "Sem dados há X",
  `zinc` "Aguardando primeiro contato". Paleta idêntica ao resto do projeto.

## 6. Exemplo de payload (teste manual)

```bash
TOKEN='<token exibido na criacao ou revelado no Cofre>'
curl -s -X POST http://192.168.11.210:8080/webhook/servers/ingest \
  -H "Content-Type: application/json" \
  -H "X-Agent-Token: $TOKEN" \
  -d '{
    "agent_version": "1",
    "ts": 1751889600,
    "cpu_pct": 37.2,
    "cpu_count": 8,
    "load": [1.2, 0.9, 0.7],
    "mem":  {"total_mb": 16000, "used_mb": 9200, "pct": 57.5},
    "swap": {"total_mb": 4096,  "used_mb": 120,  "pct": 2.9},
    "disks": [{"mount": "/", "total_gb": 100, "used_gb": 63, "pct": 63.0}]
  }'
# -> {"received":true}; o selo do servidor vira "Recebendo dados" por 90s
```

**Smoke real executado** (app servido em 127.0.0.1:8899, Redis/predis de verdade):
200 válido / 401 sem token / 422 malformado; buffer no Redis (db 1, TTL ativo) e
`last_seen_at` no MySQL confirmados. O servidor de smoke (`srv-smoke-dev`, grupo `teste`,
conta 1) **ficou no inventário** com token no Cofre (`agente-servidor-1`) — útil para o
Fabio testar pela UI; pode excluir/regenerar por lá.

## 7. Testes (2 arquivos novos, 26 testes / 100 assertions — suíte sequencial)

**`ServersIngestTest`** (14): token válido 200 + buffer + last_seen; **sem token 401 sem
gravar**; token errado 401 (e warning no log, sem o token); desativado 403 sem gravar;
**malformado 422** sem efeito colateral; **gigante 413**; **rate limit 429** no 11º POST;
**isolamento** (token de A não toca B); **trim** da janela (70 → 60, mais recente primeiro);
**TTL** expira; **last_seen sobrevive a `Cache::flush`** (watchdog não cega); guard-rail
"nenhuma tabela de histórico"; log **idempotente por janela** (2 POSTs = 1 evento);
**token nunca em log nem em claro na tabela**.

**`ServersInventarioTest`** (12): owner acessa / **operador 403 na rota** / item **some do
menu** do operador (e aparece pro owner); **ação Livewire forjada de operador barrada**
(403, nada criado); criar gera token no Cofre (referência + hash na tabela, claro só no
vault, exibido uma vez, `dismissToken` descarta) e o token **autentica a ingestão fim a
fim**; **regenerar invalida o anterior** (401/200); só `linux` na v1; nome duplicado
rejeitado; editar não toca o token; **excluir remove servidor + segredo** (sem órfão);
desativar/reativar ingestão (403 ↔ 200).

## 8. Ajustes deliberados (um a um)

1. **Model em `App\Servers`** (não `App\Models`): instrução explícita da fatia — toda a
   feature num namespace só; desvio consciente da convenção do repo, registrado.
2. **Coluna `agent_token_hash` além do `agent_token_secret_ref`**: o prompt listava só a
   referência; sem o hash, cada POST exigiria decifrar N segredos do Cofre para achar o
   dono. O hash (desenho da Fase 0) dá lookup O(1) e **não é o token em claro**.
3. **Coluna `last_sample`**: do schema da Fase 0; mesma UPDATE do `last_seen_at` (custo
   zero) e poupa migration na S4 (painel).
4. **Buffer via `Cache` facade** (não LPUSH/LTRIM cru): hermetismo da suíte (array store) +
   escritor único por chave; em produção é Redis com TTL — provado no smoke (seção 2).
5. **413 para payload gigante** (o prompt dizia "422/rejeição rápida"; 413 é o código
   correto para tamanho — 422 ficou para o malformado).
6. **403 para servidor desativado** (token válido + ingestão desligada ≠ 401).
7. **Componente `Inventario`** (rota/name `servidores`): nomeação da Fase 0 — as sub-páginas
   Painel/Alertas/Incidentes chegam nas fatias S2-S4 sem renomear nada. **Sem placeholders**
   (o mecanismo de sidebar não exige).
8. **Log de ingestão OK = 1/hora por servidor** (ref idempotente): atende "registrar
   ingestões" sem inflar o /logs (2.880-5.760 eventos/dia/servidor seria o custo do literal).
9. **Reparo do ambiente DEV** (composer/npm/15 migrations de fatias anteriores) — seção 0.
10. **Limites do throttle**: 10/min por token e 30/min por IP (cadência legítima: 2-4/min).
11. **Servidor de smoke deixado no inventário** (seção 6) — remoção é 1 clique na UI; agente
    não executa DELETE autônomo em dados que o dono pode querer inspecionar.
12. **Pint** aplicado nos arquivos novos (estilo do repo); suíte re-rodada verde depois.

## 9. Confirmações finais

- **Produção/Nextgest/nginx/Tunnel intocados** (trabalho 100% na instância DEV local).
- **Pipeline/matching/FlowEngine/Kanban/billing/sender: zero diff** — os únicos arquivos
  existentes tocados foram os 4 pontos de extensão previstos (+31 linhas):
  `routes/web.php` (2 rotas), `AreaAccess::MAP` (1 linha), `app.blade.php` (item do menu),
  `AppServiceProvider` (rate limiter). Serviço de envio WhatsApp **nem referenciado**.
- **Sem avaliação de alerta, sem WhatsApp, sem coletor instalado** — fronteira S1 respeitada
  (S2/S3/S5). Nenhum job enfileirado pela ingestão (grava buffer e responde).
- **`php artisan queue:restart` executado** (2026-07-07 02:57:20) — `AppServiceProvider` é
  carregado por worker; os units systemd de worker/scheduler não estão ativos nesta máquina
  (sinal fica no cache para quando subirem).
- Migrations aditivas aplicadas e **confirmadas por leitura** (`php artisan db:table servers`).
- Suíte inteira **sequencial**: 949→975 verdes (3775→3875 assertions), zero falha.
- Commit `4b94858` (código + doc Fase 0), **sem push** (repo da instância DEV; Fabio empurra).
