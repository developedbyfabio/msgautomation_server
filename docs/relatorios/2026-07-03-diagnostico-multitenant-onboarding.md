# Diagnóstico multi-tenant — onboarding de novo cliente (SÓ LEITURA) — 2026-07-03

Git no início: `working tree clean`, `git diff --stat` vazio, HEAD `494e3e9`. Único arquivo novo é
este relatório. Nada tocado.

---

## VEREDITO

### PRONTO (a fundação aguenta um novo tenant)
- **Isolamento por conta: sólido.** 23 models usam `BelongsToAccount` (escopo global + high-fail sem
  contexto). Os 11 sem o trait são **filhos escopados pela FK do pai** (BoardColumn→Board,
  FlowNode/Option/Trigger→Flow, RuleResponse/Trigger/AiExample→Rule, CampaignTarget→Campaign,
  CardTransition→Card) ou entidades transversais por design (Account = o próprio tenant; User =
  vínculo N:N via `account_user`). Nenhum model de domínio ficou desescopado por acidente.
- **Vínculo usuário↔conta (MT-1):** pivot `account_user` com `role`; `SetAccountContext` resolve a
  conta do request pelo vínculo do usuário logado; usuário sem vínculo = 403.
- **Canal por conta no schema (MT-2):** `channels` com `account_id`, `provider`, `webhook_token`,
  `credentials` cifradas; webhook **por token** já roteia pra conta certa.
- **Provisionamento Evolution já existe no BACKEND:** `ChannelProvisioner::provision($account)` cria
  canal com **instância única por tenant** (`conta-{id}-{slug}`), gera `webhook_token`, **cria a
  instância na Evolution** (`POST /instance/create`) e **configura o webhook por token** —
  automático e idempotente. Exposto por `php artisan evolution:setup --account=`.
- **Provisionamento Cloud já existe no BACKEND:** `php artisan msg:channel:create-cloud --account=`
  cria o canal Cloud com credenciais cifradas (token via prompt oculto, nunca em log).
- **UI de conexão (QR) existe e é por conta:** `/conexao` (fora do gate `whatsapp.connected`) mostra
  o QR do **canal da conta ativa** (`Channel::query()->oldest()` já escopado) e detecta `open`.
- **Toda conta nova nasce funcional:** `Account::booted()` provisiona board Kanban default +
  variáveis de sistema automaticamente.
- **Cobertura de testes de isolamento: forte** — `TenantIsolationTest` (28 testes) cobre webhook→
  conta, secrets, telas, IA, tetos/cota, kill switch, high-fail, kanban, tags, proativas, campanhas,
  painel, variáveis, roteamento Cloud, token por canal, HTTP por usuário.

### FALTA (o fluxo self-service — do maior gap ao menor)
1. **Signup / criação de conta pela UI (maior gap):** não há rota `register`/`signup` — só
   `/login`. Não existe **nenhum comando nem UI** que crie um `Account` novo (o `Account::create` só
   aparece no seeder/provisioners). Um tenant novo hoje só nasce via `tinker`/seeder manual.
2. **Criação do 1º usuário do tenant pela UI:** `php artisan user:create --account=` existe (vincula
   como `owner`), mas **exige uma conta já existente** e é artisan-only. Sem signup, não há como o
   cliente criar o próprio login.
3. **UI para disparar o provisionamento do canal:** `/conexao` só mostra o QR de um canal **que já
   existe**; se a conta não tem canal (`canal()` retorna null), não há botão/fluxo pra rodar o
   `ChannelProvisioner`/`evolution:setup` nem pra criar canal Cloud. Todo o provisionamento
   (criar instância Evolution, gerar token/webhook, cadastrar credenciais Meta) é **artisan hoje**.
4. **Entrada de credenciais Cloud pela UI:** phone_number_id/access_token/app_secret/verify_token só
   entram via `msg:channel:create-cloud` (prompt oculto). Sem tela.
5. **Escolha de provedor no onboarding (Evolution vs Cloud):** não há UI que pergunte/decida o canal
   do novo tenant.
6. **Orquestração de onboarding:** nada amarra "criar conta → criar owner → provisionar canal →
   conectar" num fluxo; hoje são 3-4 comandos manuais em ordem.

### FATIAS RECOMENDADAS (ordem sugerida — NÃO implementadas)
1. **Comando/serviço `account:create`** (wrapper de `Account::create` + owner) — base pros demais;
   trivial e destrava o resto.
2. **Signup UI** (registro público: nome, email, senha → cria Account + User owner + AccountContext),
   com decisão do Fabio sobre signup aberto vs. convite/aprovação.
3. **UI de conexão de canal self-service:** em `/conexao`, se a conta não tem canal, botão
   "Conectar WhatsApp" que roda o `ChannelProvisioner` (Evolution: cria instância + webhook + mostra
   QR) — o backend já faz tudo, falta o gatilho na tela + estado "provisionando".
4. **UI de canal Cloud:** form pra credenciais Meta (cifradas) reusando `msg:channel:create-cloud`.
5. **Escolha de provedor no onboarding** + textos/limites por plano (se aplicável).
6. **Teste de isolamento do onboarding:** garantir que criar tenant B pela UI não vaza pro A
   (estender `TenantIsolationTest`).

---

## Evidências

### Bloco 1 — Conta/tenant e vínculo
- `app/Models/Account.php:9` model `Account` (tabela `accounts`), `fillable=['name']` (`:18`);
  `booted()` (`:25-31`) auto-provisiona board + variáveis em toda conta criada; `users()` N:N via
  `account_user` (`:12-15`).
- Conta nasce só via seed/provisioner: `Account::firstOrCreate` em
  `database/seeders/DatabaseSeeder.php:24` (conta-âncora). **Nenhum comando `account:create`.**
- `app/Models/User.php:35-38` `accounts()` belongsToMany `account_user`. Vínculo criado em
  `app/Console/Commands/UserCreate.php:65` (`syncWithoutDetaching([$account->id => ['role'=>'owner']])`);
  a conta vem de `--account` ou `Account::oldest()` (`:30-32`) — **exige conta preexistente**.
- `app/Http/Middleware/SetAccountContext.php`: conta do request = vínculo do usuário; sem vínculo →
  403. Usuário PODE existir sem conta no schema, mas não opera nada (403).

### Bloco 2 — Onboarding / signup
- `routes/web.php:18-26`: grupo `guest` só tem `/login` e `/two-factor-challenge`. **Sem `register`/
  `signup`.** Login é `App\Livewire\Login`. Não há criação de usuário/conta pela UI.

### Bloco 3 — Isolamento
- COM `BelongsToAccount` (23): AiDecision, AutoReplyLog, AutoReplyRule, AutoReplySetting, Board,
  BoardRule, Card, Channel, Contact, ContactChannelWindow, Flow, FlowSession, Group, IncomingMessage,
  Knowledge, PendingApproval, ProactiveCampaign, ProactiveConsent, Secret, SystemEvent, Tag,
  UnmatchedMessage, Variable.
- SEM o trait (escopados pela FK do pai, ou transversais): BoardColumn, CampaignTarget,
  CardTransition, FlowNode, FlowOption, FlowTrigger, RuleAiExample, RuleResponse, RuleTrigger
  (todos filhos, cascade), + Account e User (transversais por design). **Nenhum domínio desescopado
  por acidente.**
- `TenantIsolationTest` (28 testes, `tests/Feature/TenantIsolationTest.php:122-728`) — cobre o
  runtime multi-tenant. **NÃO cobre** (porque não existe): signup/criação de conta pela UI,
  provisionamento de canal pela UI.

### Bloco 4 — Canal por conta
- Canal atual criado por seed (`DatabaseSeeder.php:27` `Channel::firstOrCreate` instância do `.env`)
  e/ou `evolution:setup`. UI de QR: `app/Livewire/Conexao.php:31-33` `canal()` =
  `Channel::query()->oldest('id')->first()` (escopado por conta) — **só exibe QR de canal
  existente; não cria canal/instância.** Cloud: só `msg:channel:create-cloud`
  (`app/Console/Commands/ChannelCreateCloud.php:23`, `--account`, prompt oculto). **Sem UI** para
  conectar WhatsApp novo (nem QR-provisioning, nem credenciais Cloud).

### Bloco 5 — Provisionamento de infra por tenant
- **Evolution:** `app/Channels/Evolution/ChannelProvisioner.php:29-74` — instância única por conta
  (`uniqueInstanceName`: `conta-{id}-{slug}`, `:` no fim do arquivo), `webhook_token=Str::random(48)`
  (`:39`), **cria a instância** (`api->createInstance()`, `:59`) e **configura webhook por token**
  (`setWebhook(tokenUrl)`, `:70`), idempotente. Disparado por `php artisan evolution:setup --account=`
  (`app/Console/Commands/EvolutionSetup.php:17,33`). Instâncias isoladas por tenant. **Automático no
  backend; falta gatilho na UI.**
- **Webhook por token (MT-2):** gerado **automaticamente** no provisionamento (`webhook_token` +
  `tokenUrl` = `services.evolution.webhook_url` + `/` + token).
- **Cloud API:** credenciais Meta do cliente entram **só por artisan** (`msg:channel:create-cloud
  --account=`, token via prompt oculto, cifrado no canal). Sem tela.

---

## Confirmação de que nada foi tocado
- `php artisan test`: **614 verdes** (2283 assertions) — código intocado.
- `git status`: limpo, exceto o novo `docs/relatorios/2026-07-03-diagnostico-multitenant-onboarding.md`.
- Nenhuma migration/schema/delete; nenhum commit/push.
