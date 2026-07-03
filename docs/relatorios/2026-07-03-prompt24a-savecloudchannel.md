# Prompt 24a — Extrair SaveCloudChannel (service testável) e delegar o comando — 2026-07-03

**Status: ENTREGUE.** Baseline 622 → **632 verdes** (+10), `TenantIsolationTest` 28. Sem migration.
Provider/webhook/reativo intocados. Comportamento do comando preservado (com uma mudança intencional
documentada abaixo).

## Ordem seguida: caracterização → extração → delegação

### Passo 1 — Caracterização (travada ANTES de mexer)
`CloudChannelSaveTest` (4 testes de comando), verdes contra o comando **original**, depois
verdes de novo após a delegação — prova de comportamento preservado:
- **create** com verify informado → canal `cloud_api` escopado à conta, campos corretos, `credentials`
  **cifrado** em repouso (segredos não aparecem em claro na coluna).
- **verify vazio → gerado** (`Str::random(32)`).
- **`--update`** → atualiza credenciais e **preserva `webhook_token` e `instance`**; não duplica canal.
- **phone_number_id inválido** (fora de `^\d{5,20}$`) → falha após só a pergunta do phone, nada persistido.
(O teste já existente `CloudApiProviderTest::test_comando_cria_canal_...` também segue verde.)

### Passo 2 — Action extraído: `App\Channels\CloudApi\SaveCloudChannel`
Assinatura: `handle(Account $account, array $input, bool $update = false): SaveCloudChannelResult`
(`$input` = phone_number_id, waba_id, access_token, app_secret, verify_token). Faz **só lógica pura**
(sem I/O de CLI): validação (phone regex, obrigatórios), decisão create vs update (por conta +
phone_number_id), **preservação de `webhook_token`/`instance`** no update, geração de verify quando
vazio, montagem e **cifra** via `channels.credentials` (`encrypted:array` — reusado, não
reimplementado). Escopo estrito à conta recebida.

`SaveCloudChannelResult` (DTO neutro, apresentável por CLI e UI): `ok`, `channel`, `error`,
`verifyGerado`, `warning` — via `fail()` / `success()`.

### Passo 3 — Comando delega
`msg:channel:create-cloud` mantém as **perguntas e mensagens idênticas** e os **early-exits de UX**
(phone regex + existência/`--update`, pra falhar antes de pedir segredos), e ao fim chama
`SaveCloudChannel::handle(...)`, apresentando `warning` via `warn` e `error` via `error`. A exibição
final (Callback URL + verify) veio do `result->channel`. Caracterização segue verde passando pelo Action.

### Passo 4 — Testes do Action isolado (6)
create cifrado+escopado; update preserva webhook_token/instance; **anti-swap como erro sem I/O**
(verify "EAA" → `ok=false`, nada persistido); **aviso** de access_token sem "EAA" (não bloqueia,
`warning` retornado); verify vazio → gerado; **isolamento** (salvar em A não cria em B).

## Anti-swap: como virou erro (mudança intencional, documentada)
- **Antes (CLI):** `verify_token` começando com "EAA" → `confirm(..., false)`; declinar abortava,
  **confirmar deixava prosseguir** (escape hatch interativo).
- **Agora (Action):** verify "EAA" → **erro estruturado** (`result->error`), **sempre rejeitado**,
  sem confirm. `access_token` sem "EAA" → **`warning`** não bloqueante (igual ao aviso de antes).
- **Impacto observável no comando:** a única mudança é a remoção do "confirmar pra usar mesmo assim"
  no caso verify-"EAA" (agora hard-fail). Nenhum teste travava esse caminho de override; a UI (24b)
  não teria como oferecer confirm de qualquer forma. Todos os outros comportamentos idênticos.

## Webhook URL: preservado (NÃO usei `route()`)
Confirmado o risco de divergência que o Passo 1 antecipou: `route('webhook.cloud', $t)` resolve para
`https://painel.nextgest.com.br/...` (APP_URL), mas o webhook Cloud vive em **`wa.nextgest.com.br`**
(subdomínio próprio). Usar `route()` geraria a **Callback URL errada**. Então **preservei o valor
atual hardcoded** no comando (`https://wa.nextgest.com.br/webhook/cloud/{token}`), inalterado. O
Action **não** lida com a URL de exibição (é presentação) — a 24b deve montar a URL com essa mesma
base (recomendo extrair pra config `services.cloud_api.webhook_base` na 24b pra não hardcodar em dois
lugares).

## GATE (confirmado)
- Comando idêntico (caracterização verde antes e depois); só o anti-swap-override saiu (intencional).
- `CloudApiProvider`, webhook handler (GET/POST/HMAC/dedup) e pipeline reativo **intocados**
  (`git diff` só toca o comando + arquivos novos).
- Credenciais nunca logadas; persistência só `encrypted:array` (teste confirma cifrado em repouso).
- Escopo por conta; isolamento intacto (`TenantIsolationTest` 28).

## Arquivos
Novos: `app/Channels/CloudApi/SaveCloudChannel.php`, `SaveCloudChannelResult.php`,
`tests/Feature/CloudChannelSaveTest.php`. Alterado: `app/Console/Commands/ChannelCreateCloud.php`
(delega). **Suíte: 632 verdes.**

## Próximo (24b)
Form na `/conexao` (aba Cloud API) consumindo `SaveCloudChannel`: máscara dos segredos, exibição da
Callback URL (base `wa.nextgest.com.br`, idealmente via config nova) + verify_token, escopo à conta
ativa.
