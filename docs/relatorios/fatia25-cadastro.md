# Fatia 25 — Cadastro público PF/PJ + trial de 7 dias + verificação de e-mail — 2026-07-06

Git no início: HEAD `eadd9b7` (fatia 24), working tree limpo exceto os dois relatórios untracked
de sessões anteriores (fora do commit). Baseline: **896 verdes / 3576 assertions**.

---

## Mapeamento (lido ANTES de escrever)

- **Auth/registro existente:** Fortify 1.36 com **só** `Features::twoFactorAuthentication`
  (`views => false`) — registro/reset ficam FORA do Fortify por decisão do Prompt 01. Login é
  Livewire próprio (`App\Livewire\Login`) com throttle duplo (email+IP 5/min, IP 20/min).
  **Não havia nenhuma rota de registro** (criação de tenant era só `/admin` + console).
- **Criação de tenant no /admin:** `App\Actions\CreateTenant` é o **ponto único** (transação:
  Account + User owner + pivot; board/variáveis via `Account::booted()`). **Reusado, não
  duplicado**: o novo `RegisterTenant` o embrulha.
- **Rate limiting reusado:** o padrão do login hardening (Prompt 28) — chaves `RateLimiter`
  por e-mail+IP e por IP, mensagem "Muitas tentativas...".
- **MustVerifyEmail:** import comentado no `User` (nunca ativado). `users.email_verified_at` já
  existia no schema. Fortify tem `Features::emailVerification` disponível — habilitado (mesmo
  desenho do 2FA: pipeline do Fortify, views nossas).
- **Layout guest:** casca da Fatia 21 (`components/layouts/auth`), mesma usada pelo 2FA.
- **Schema da conta:** `accounts` era mínimo (id, name, timestamps) — perfil PF/PJ e trial
  entram lá, aditivos. `accounts.name` **não tem unique de banco** (só validação no admin).

## Surpresa registrada (adaptação consciente)

A suíte legada tem **25 arquivos** que criam `User::create(...)` (sem `email_verified_at`) e
batem em rotas autenticadas via HTTP. Pôr `verified` no grupo de rotas quebraria todos.
**Adaptação:** *verificado por construção* — hook `creating` no `User`: se `email_verified_at`
não foi setado, nasce `now()`. Racional: **criar usuário é ato privilegiado** em todos os
caminhos (console `user:create`, `/admin/tenants`, `CreateTenant`) — quem cria responde pelo
e-mail. A **única origem não-vouched é o cadastro público**, e o `RegisterTenant` marca
não-verificado **explicitamente** (via `forceFill`; o atributo não é fillable — passar
`email_verified_at => null` no `create()` é descartado pelo guard, fricção proposital).
Resultado: **zero ajuste** nos 25 arquivos legados; o gate continua inviolável para quem veio
do signup.

## Migration (aditiva, forward, foreground) + backfill

`2026_07_06_000005_add_signup_profile_and_trial`:
- `accounts`: `person_type` (pf|pj), `document` (CPF/CNPJ só dígitos, **UNIQUE** nullable),
  `razao_social`, `phone`, `cep`, `endereco`, `numero`, `complemento`, `bairro`, `cidade`,
  `uf`, `subscription_status` (**default 'active'**), `trial_ends_at` (nullable).
- `users`: `terms_accepted_at`, `terms_version` (consentimento LGPD).
- **Backfill:** `UPDATE users SET email_verified_at = created_at WHERE email_verified_at IS NULL`
  (só preenche NULL; pré-fatia-25 todo usuário nasceu por caminho privilegiado). Sem isso, o
  gate `verified` novo trancaria os usuários do Fabio.

**Decisão para contas existentes:** ficam `subscription_status='active'` (default da coluna),
sem trial, sem perfil PF/PJ (null) — operam exatamente como hoje. Idem para tenants criados
pelo admin daqui pra frente. Só o cadastro público grava `'trial'`.

**Read-back em produção (após `migrate --force`, 649ms):** conta 1 "msgautomation" e conta 2
"Teste" → `active`, `trial_ends_at` null, perfil null (**intactas**); 3 usuários com
`email_verified_at` preenchido = created_at, **0 não-verificados**; rotas `cadastro`,
`verification.notice`, `verification.verify`, `verification.send` registradas. Smoke real:
`GET /cadastro` (host de produção, loopback) → **200** com plano e trial renderizados.

## Provisionamento atômico

`App\Actions\RegisterTenant` (única transação; a interna do `CreateTenant` vira savepoint):
1. `CreateTenant->handle(...)` → Account + User + pivot **owner** (+ board/variáveis).
2. `$account->forceFill([perfil PF/PJ..., subscription_status='trial', trial_ends_at=now()+7d])`.
3. `$owner->forceFill([email_verified_at=null, terms_accepted_at=now(), terms_version=config])`.

Qualquer falha → **rollback total** (provado por teste: unique de e-mail estourando DENTRO da
transação → nenhuma conta órfã, nenhum user novo). E-mail de verificação disparado **depois**
do commit (best-effort com `report()` — SMTP fora não derruba o cadastro; a tela de aviso tem
reenvio). Depois: `Auth::login` + regenerate + `tenancy.account_id` na sessão → notice.

## Validação

- **CPF** (`App\Rules\Cpf`): módulo 11 real, dois DVs, rejeita sequências repetidas.
  **CNPJ** (`App\Rules\Cnpj`): pesos 5..2/9..2 e 6..2/9..2, idem. Verificados contra
  CPFs/CNPJs válidos e inválidos conhecidos antes dos testes.
- **Normalização server-side antes de validar:** documento/CEP/telefone → só dígitos
  (unicidade compara forma canônica), e-mail lowercase, UF uppercase.
- **CEP:** ViaCEP **client-side** (fetch no blur, Alpine) preenchendo via
  `preencherEndereco()` (que sanitiza: corta tamanho, UF fora das 27 é ignorada). Serviço
  fora / offline → **fallback manual** (campos continuam editáveis). O submit **revalida
  tudo** server-side (CEP size:8, UF `in:` 27, obrigatórios, tamanhos) — não confia no front.
- **Senha:** min:10 + confirmed (mesma política da criação de tenant do admin — a mais
  estrita do projeto; `Password::default()` do Fortify é min 8).
- **LGPD:** checkbox obrigatório (`accepted`); consentimento auditável em
  `users.terms_accepted_at` + `terms_version` (versão em `config/billing.php`).
- Mensagens de validação em **pt-BR explícitas** no componente (não há `lang/`; é a primeira
  tela client-facing).

## Verificação de e-mail

- `User implements MustVerifyEmail` + `Features::emailVerification()` do Fortify: GET
  `email/verify/{id}/{hash}` (**signed** + throttle 6/1) e POST
  `email/verification-notification` (reenvio, throttle 6/1) — código mantido do framework.
- Tela de aviso nossa: `GET /email/verify` (`verification.notice`, auth SEM verified,
  layout da Fatia 21, reenvio + sair).
- **Painel inteiro atrás de `verified`:** o grupo `Route::middleware(['auth','verified'])`
  agora cobre todas as rotas do painel (conexao, perfil, senhas, media, admin, operacionais).
  Fora dele: login/cadastro/2FA (guest), logout, conta-ativa, notice.
- **Defesa em profundidade:** `EnsureEmailIsVerified` também é persistent middleware do
  Livewire (updates de componente re-aplicam o gate, como o `EnsureAccountRole` da Fatia 22).
- E-mail em pt-BR via `VerifyEmail::toMailUsing` (só o texto; link assinado nativo).
- **Variáveis que o Fabio precisa setar no `.env` quando escolher o transporte:**
  `MAIL_MAILER` (hoje `log` — fluxo funciona, e-mail cai em `storage/logs`), `MAIL_HOST`,
  `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME`,
  `MAIL_FROM_ADDRESS` (hoje `hello@example.com` — **trocar antes do lançamento**),
  `MAIL_FROM_NAME`. `APP_URL` já está correto (links assinados saem certos). Zero credencial
  no código.

## Estado de trial (fronteira para a Fatia 26 — REGISTRADA)

- Campos: `accounts.subscription_status` ('trial' no signup; 'active' legadas/admin) +
  `accounts.trial_ends_at` (= now()+`config('billing.trial_days')`=7). Cast datetime no model.
- **O corte NÃO foi implementado** — nenhum bloqueio/aviso/downgrade no vencimento. Provado
  por teste: trial vencido há 3 dias → painel segue 200. A Fatia 26 lê
  `subscription_status`/`trial_ends_at` e decide o corte junto do gateway (estados
  `active`/`overdue`/`suspended`/`canceled` entram lá).
- `config/billing.php` é o **ponto único**: plano (nome/preço/força por env — preço é
  **placeholder** `149,90` claramente marcado), `trial_days`, `terms_version`.

## Anti-abuso

- Rate limiting no `cadastrar()` (padrão do login hardening): submissões por e-mail+IP
  (6/10min) e por IP (15/10min) — freios de bot; **criações por IP: 3/hora** (um IP não
  fabrica farm de contas trial).
- `accounts.document` **UNIQUE** (1 CPF/CNPJ = 1 conta, constraint de banco + validação) e
  e-mail único.
- **Sem CAPTCHA** (nada trivial no stack — Flux free/sem serviço externo); registrado como
  reforço futuro se abuso aparecer.

## Testes novos (22 casos, 2 arquivos)

`CadastroTest` (17): página renderiza com plano p/ visitante; PF provisiona
user+conta+**pivot owner**+trial≈+7d atomicamente (normalização, LGPD, não-verificado,
logado, notificação enviada); PJ (fantasia vira nome da conta, razão social guardada); PJ
exige razão social; **rollback total** em falha no meio (nível action, bypass do form);
**isolamento A/B** do tenant novo (não vê e não é visto — `runAs` dos dois lados); CPF DV
errado rejeitado; CNPJ DV errado rejeitado; documento duplicado rejeitado (1 pessoa = 1
conta); e-mail duplicado; endereço revalidado server-side (UF forjada, CEP curto, endereço
vazio); `preencherEndereco` ignora UF inválida e corta tamanho; aceite obrigatório; rate
limit de submissões; rate limit de criações; **trial vencido não bloqueia nada** (corte é da
26); contas criadas pelo admin seguem `active` sem trial.

`VerificacaoEmailTest` (5): não-verificado barrado no painel (perfil e conexao → notice) e
vê o aviso; reenvio dispara notificação; **link assinado verifica e libera**; **link forjado
(hash de outro e-mail) → 403** e segue não-verificado; caminhos privilegiados
(CreateTenant/User::create) nascem verificados por construção — regressão da suíte legada.

## Ajustes deliberados em testes existentes

**Nenhum.** (O desenho "verificado por construção" absorveu o gate `verified` sem tocar os
25 arquivos legados.)

## Contagem de testes

| | testes | assertions |
|---|---|---|
| Antes | 896 | 3576 |
| Depois | **918** | **3672** |

Suíte inteira **sequencial**, tudo verde. Build do Vite/Tailwind **foreground** (classes
novas dos blades).

## Confirmações explícitas

- **Pipeline/motor/matching/2FA: zero diff** (`git diff --stat app/Whatsapp app/Jobs
  app/Kanban app/Listeners app/Events` = 0 linhas). Proteção admin/último-owner intocada.
- Contas 1 e 2 **intactas** (read-back acima); nenhum dado escopado tocado.
- Nextgest/nginx/Tunnel intocados. Sem operação destrutiva. Sem push.
- **`queue:restart` NECESSÁRIO e executado** (User/Account são models carregados por jobs;
  AppServiceProvider mudou): worker reciclado pid 284997 (16:47) → **pid 298978 (17:06)**.
  Workers do Nextgest intocados (sinal de restart é por app, via cache do próprio app).

## Decisões registradas (menores)

- Nome de conta **não exige unicidade** no signup (documento é a âncora única; id desambigua
  no admin — "João Silva" não pode ser bloqueante).
- Tenants criados pelo admin nascem `active` (sem trial) — admin não é canal de venda.
- Troca de e-mail no /perfil **não** re-exige verificação (código próprio, fora do Fortify;
  usuário já está dentro e re-autentica ações sensíveis) — endurecimento futuro se preciso.
- Texto de Termos de Uso/Política de Privacidade **não existe** (checkbox registra aceite +
  versão): redigir e publicar é decisão do dono antes do lançamento (follow-up).
- E-mail de verificação enviado direto (`sendEmailVerificationNotification`), não via evento
  `Registered` (determinístico; nada mais escuta registro hoje).

## Commit
Local, sem push (Fabio empurra). Hash na resposta.
