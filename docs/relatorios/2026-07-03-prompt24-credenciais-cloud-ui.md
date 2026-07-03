# Prompt 24 — Credenciais Cloud API pela UI — PARADO no Passo 1 (regra de parada) — 2026-07-03

**Status: PARADO na investigação, conforme a regra de parada do Passo 1.** Nenhum código tocado.
Suíte segue **622 verdes**; árvore limpa (só este relatório). Baseline intacto.

## Por que parei (a condição de parada do enunciado foi atingida)
O Passo 1 diz textualmente: *"Se o Action não for reusável sem refactor, ou faltar algo, PARE e
reporte antes de prosseguir"*. E o enunciado repete no fim: *"Se algo exigir ... refactor do Action
... PARE."* Essa condição está **plenamente atingida**:

- **NÃO existe Action/Service reusável** por trás de `msg:channel:create-cloud`. Toda a lógica
  (validação de `phone_number_id`, anti-swap "EAA", montagem das credenciais, criar vs. `--update`,
  persistência cifrada, exibição do webhook) vive **inline** em `ChannelCreateCloud::handle()`,
  **acoplada ao I/O interativo do CLI** (`$this->ask`, `$this->secret`, `$this->confirm`,
  `$this->warn`, `$this->error`, `return self::FAILURE`).
- Reusar isso na UI exige **extrair/refatorar** essa lógica pra fora do comando — ou seja, o
  "refactor do Action" que o enunciado marca como condição de parada.
- **Duplicar** a lógica + a criptografia no form é **explicitamente proibido** pelo enunciado
  ("Não duplique a lógica nem reimplemente a criptografia").
- Agravante de risco: o comando manipula **credenciais do tenant Cloud VIVO do Fabio** e **não tem
  teste** cobrindo a própria lógica de create/update/anti-swap (só `CloudApiProviderTest` e
  `CloudApiParteBTest`, que testam o *provider*, não o comando). Refatorar código de credenciais em
  produção **sem rede de testes** é risco que não devo assumir por conta própria.

Como as três saídas possíveis são "extrair (refactor)", "duplicar (proibido)" ou "parar", e o
enunciado manda parar exatamente nesse caso, **paro e trago a decisão pro Fabio**.

## Achados do Passo 1 (investigação completa — base pra próxima fatia)

### 1. Campos do comando (`ChannelCreateCloud`, ordem fixa) — o form vai espelhar
| Campo | Obrigatório | Regra | Sensível |
|---|---|---|---|
| `phone_number_id` | sim | `^\d{5,20}$` (só dígitos); vira `channels.instance` (roteamento do webhook) | não |
| `waba_id` | sim (perguntado) | numérico (WhatsApp Business Account id) | não |
| `access_token` | **sim** | token grande da Meta (começa com `EAA`) | **sim (password)** |
| `app_secret` | **sim** | hex curto (App settings > Basic) | **sim (password)** |
| `verify_token` | opcional | string curta inventada; vazio → **gerado** (`Str::random(32)`) no create; no `--update` vazio mantém o atual | sim (curto) |

`api_version` NÃO é por canal (config global `services.cloud_api.graph_version`) — não entra no form.

### 2. Anti-swap guard (regra do "EAA")
- `access_token` que **não** começa com `EAA` → apenas **aviso** (`$this->warn`), não bloqueia.
- `verify_token` que **começa com `EAA`** → indício de que o access token foi colado no campo errado
  → o CLI **pede confirmação** (`$this->confirm(..., false)`); negando, **aborta sem gravar**.
- (Não há guard pro inverso — access_token que pareça verify_token — além do aviso do "EAA".)

### 3. Criptografia / persistência
- `channels.credentials` é `encrypted:array` (`app/Models/Channel.php:29`) — cifrado com a APP_KEY,
  nunca em claro no banco. O array salvo: `access_token, phone_number_id, waba_id, app_secret,
  verify_token`.
- **Create vs update:** distingue por conta + `phone_number_id` (`channels.instance`). Sem `--update`:
  se já existe canal com esse phone_number_id (na conta OU global), **recusa** (evita duplicar/roubar
  instância). Com `--update`: exige canal `cloud_api` existente e atualiza **só** `credentials`
  (`webhook_token`, `instance`, `status` preservados → a Callback URL na Meta continua válida).

### 4. Webhook por conta (o que exibir ao usuário)
- Rota: `Route::match(['get','post'], '/webhook/cloud/{token}', ChannelWebhookController)` (`routes/web.php:129`,
  name `webhook.cloud`). GET = challenge (verify_token); POST = HMAC-SHA256 do raw body.
- **Callback URL a exibir:** hoje o comando **hardcoda** `https://wa.nextgest.com.br/webhook/cloud/{webhook_token}`
  (`ChannelCreateCloud.php:128`) — **não** vem de config/`route()`. Pra UI, o certo seria
  `route('webhook.cloud', $channel->webhook_token)` ou uma config de base (evitar hardcode divergente).
- `verify_token` fica em `channels.credentials['verify_token']`; o comando o mascara na exibição
  (`abc…yz (N chars)`), exceto quando recém-gerado (mostra uma única vez).

## Recomendação (decisão pro Fabio — próxima fatia, NÃO aplicada)
Fatia **24a — extrair o serviço (pré-requisito):** criar `App\Channels\CloudApi\SaveCloudChannel`
(entrada: account, phone_number_id, waba_id, access_token, app_secret, verify_token, flag update;
saída: Channel + verify gerado?), contendo **só a lógica pura** (validação, anti-swap como regra que
devolve erro em vez de `confirm`, montagem+persistência cifrada, create/update). **Refatorar o
comando pra delegar** (mantendo o I/O do CLI e o comportamento), e **adicionar testes** cobrindo
create/update/anti-swap/duplicidade — hoje inexistentes. Só então:
Fatia **24b — form na `/conexao`** (aba "Cloud API") consumindo o serviço, com máscara dos segredos,
exibição da Callback URL via `route('webhook.cloud', token)` + verify_token, escopo à conta ativa.

Fazer 24a com testes primeiro tira o risco de mexer em credenciais do tenant vivo sem rede, e só
depois a UI (24b) fica trivial e segura. Recomendo aprovar 24a como fatia própria.

## Confirmação
- `php artisan test`: **622 verdes** — nada tocado.
- `git status`: limpo (só este relatório novo). Nenhuma migration/refactor/alteração de provider,
  webhook ou comando.
