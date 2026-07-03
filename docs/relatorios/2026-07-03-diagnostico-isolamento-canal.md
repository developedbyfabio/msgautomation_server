# Diagnóstico — isolamento real do canal por tenant (SÓ LEITURA) — 2026-07-03

Git no início: `working tree clean`, `git diff --stat` vazio, HEAD `d8a6173`. Só este relatório é novo.

---

## Como funciona hoje (conta → canal → status/envio/recebimento), em linguagem direta

- Cada conta tem canal(is) na tabela `channels` (`account_id`, `provider`, `instance`,
  `webhook_token`, `credentials` cifradas). O **contexto da conta** (`AccountContext`) vem do
  usuário logado (vínculo `account_user`, via `SetAccountContext`). Os models de domínio têm escopo
  global por conta (`BelongsToAccount` → `AccountScope`).
- **Recebimento (isolado):** o WhatsApp/Meta chama o webhook com o **token** (Cloud) / **instância**
  (Evolution) → o sistema acha o canal por isso → seta a conta dona → grava na conta certa. Conta
  sem canal não recebe nada.
- **Envio (isolado):** responde pelo **canal do incoming** (reativo) ou pelo `defaultFor(conta)`
  (proativo) — sempre um `Channel` explícito, cuja `instance` é a do tenant. O servidor Evolution
  (`base_url`/`apikey`) é **infra compartilhada**; o que separa os tenants é a **instância**.
- **Status/conexão (VAZA):** o indicador "conectado" do header e algumas ações pegam "o canal da
  conta" com `Channel::query()->oldest()` (escopado). Mas quando a conta **não tem canal**, isso é
  `null` e `provider->api(null)` **cai no canal global do `.env`** (`EVOLUTION_INSTANCE=fabio-pessoal`).
  Resultado: o tenant novo vê o status do WhatsApp do Fabio.

---

## VEREDITO

- **O vazamento é de STATUS (exibição) E de AÇÕES de conexão** (reconectar/QR e **logout**) — **não**
  de dados, nem de envio, nem de recebimento. É pior que só cosmético: um tenant sem canal que clique
  em "Desconectar" no header **deslogaria o WhatsApp do Fabio** (`fabio-pessoal`).
- **`.env` tem canal global servindo de fallback: SIM.** Chaves:
  `EVOLUTION_BASE_URL=http://127.0.0.1:8090`, `EVOLUTION_API_KEY=<oculto>`,
  `EVOLUTION_INSTANCE=fabio-pessoal`, `EVOLUTION_WEBHOOK_URL=...`. Lidas por
  `EvolutionProvider::credentialsFor()` (fallback) e `EvolutionApi::__construct()` (defaults).
- **Pontos exatos NÃO-escopados** (caem no `.env` quando a conta não tem canal):
  - `app/Livewire/StatusConexao.php:31` `canal()` = `Channel::query()->oldest('id')->first()` →
    `null` p/ conta sem canal → `provider->api(null)` = instância global. Usado em:
    - `:38` `connectionState()` → **display leak** (header mostra "conectado" = estado do Fabio);
    - `:97` `logout()` → **AÇÃO leak GRAVE** (desconecta o `fabio-pessoal`);
    - `:123` `connect()` → **AÇÃO leak** (mostra/reconecta o QR do Fabio).
  - `app/Http/Middleware/EnsureWhatsappConnected.php:25` `Channel::query()->oldest('id')->value('status')`
    → conta sem canal = `null` → **o gate PASSA** (não redireciona pra `/conexao`), então o tenant
    novo entra no app e vê o status vazado em vez de ser levado a conectar o próprio número.
  - Menor: `app/Whatsapp/Groups/GroupNameResolver.php:64`
    `provider->api(Channel::defaultFor($accountId))` → `null`→`.env` (só dispara em atividade de
    grupo, que exige um canal; risco baixo).
- **Raiz:** `EvolutionProvider::credentialsFor()` (`app/Channels/Evolution/EvolutionProvider.php:63-71`)
  — quando `$channel` é `null`, `instance` cai em `config('services.evolution.instance')` (linha 70).
  Para um canal EXISTENTE (mesmo com credentials vazias) usa `$channel->instance` (correto). O furo é
  estritamente o **canal null → instância global**.

---

## Bloco a bloco (evidências)

### Bloco 1 — status do header
`resources/views/components/layouts/app.blade.php:110` `<livewire:status-conexao />` (em todas as
telas). `StatusConexao::refresh()` chama `provider->api($this->canal())->connectionState()`
(`:38`); `canal()` (`:31`) é escopado, mas **null p/ conta sem canal** → `api(null)` → instância
`.env` = `fabio-pessoal`. Logado num tenant sem canal, o status lido é o de `fabio-pessoal`.

### Bloco 2 — toda leitura/uso de canal
| Ponto | Arquivo:linha | Escopado? |
|---|---|---|
| Header status/ações | `StatusConexao.php:31,38,97,123` | escopado, mas **null→.env** (VAZA) |
| Gate de conexão | `EnsureWhatsappConnected.php:25` | escopado; null passa (não força /conexao) |
| /conexao (poll/QR) | `Conexao.php:72` `defaultFor` + guarda null | **OK (corrigido no prompt 23)** |
| Envio reativo | `SendAutoReply.php:55,90` usa `$incoming->channel` (+guarda null) | **OK** |
| Envio proativo | `SendProactiveMessage.php:121` `defaultFor(conta)` | **OK** (scoped) |
| Sender (transporte) | `Sender.php:35,139` recebe `Channel` explícito | **OK** |
| Recebimento (rota) | `ChannelWebhookController.php:33` + `VerifyWebhookSecret.php:30` por `webhook_token` | **OK** |
| Recebimento (job) | `ProcessIncomingWhatsappMessage.php:89` por `instance` do payload | **OK** |
| Nome de grupo | `GroupNameResolver.php:64` `defaultFor` | null→.env (risco baixo) |
| Conversas/Regras (channel_id) | `Conversas.php:368`, `Regras.php:282` `where account_id` | **OK** (não usa api) |
| Comandos CLI | `EvolutionQr/Status/WebhookMigrate/ChannelSyncEnv/WhatsappSend` | CLI, explícito/env (fora do runtime de UI) |

### Bloco 3 — fallback global no `.env`
Sim (chaves acima). Leitores: `EvolutionProvider::credentialsFor()` (`:68-70`),
`EvolutionApi::__construct()` (`:23-25`), `ChannelProvisioner` (`:101-102`, só base/apikey ao criar),
`ChannelSyncEnv` (copia base/apikey pro canal), `WhatsappSend` (comando legado). **Num multi-tenant
não deveria haver instância global** — `base_url`/`apikey` são infra compartilhada (o servidor
Evolution é um só), mas **`instance` nunca deveria cair no global**.

### Bloco 4 — AccountContext ao logar
`SetAccountContext` resolve a conta do **vínculo do usuário logado** (owner do tenant) — correto. O
problema não é o contexto: `StatusConexao` até usa o escopo certo; o furo é o **fallback quando o
resultado escopado é null**.

### Bloco 5 — o que JÁ está isolado (dados)
Confirmado `BelongsToAccount` (escopo global) em Contact, IncomingMessage, AutoReplyRule, Card,
ProactiveCampaign, Tag, Variable, Knowledge (e demais). Por isso o tenant novo aparece **vazio**
(conversas/contatos/kanban/regras/campanhas). `TenantIsolationTest` (28) cobre esse isolamento.

**Estado do banco (confirmado):** canal id 1 (`fabio-pessoal`, conta 1) tem `credentials` completas
(instance/base_url/apikey) → **não depende do fallback do `.env`** pra funcionar. Canal id 2
(cloud_api, conta 1) não usa env Evolution.

---

## O que já está isolado vs o que falta

- **Isolado:** dados (todos os models de domínio), **envio** (canal explícito por instância) e
  **recebimento** (roteado por token/instância).
- **Falta isolar:** **status/conexão e ações de conexão** — o único furo é `provider->api(null)`
  caindo na instância global do `.env` para contas sem canal.

## Fatias de correção recomendadas (do maior risco ao menor — SEM implementar)

1. **StatusConexao escopar + guardar canal null (maior impacto).** Igual ao fix do prompt 23 na
   `Conexao`: `canal()` = `Channel::defaultFor(accountId())` e, se `null`, **não** chamar
   `provider->api()` — exibir "sem canal / conectar" (estado `disconnected`/`sem_canal`) em vez de
   status. Mata o display leak **e** os action leaks (logout/QR na instância do Fabio) de uma vez.
2. **Gate `EnsureWhatsappConnected`.** Para conta **sem canal**, redirecionar pra `/conexao` (levar o
   tenant novo a conectar o próprio WhatsApp) em vez de deixar passar. Decisão de UX do Fabio; mesmo
   deixando passar, o furo do header já morre com a fatia 1.
3. **Remover o fallback de INSTÂNCIA global no `.env`.** Em `EvolutionProvider::credentialsFor()`
   (`:70`), tirar o `?: config('services.evolution.instance')` — sem canal ⇒ sem instância (erro/no-op
   explícito), nunca `fabio-pessoal`. `base_url`/`apikey` **podem continuar** vindo do env como infra
   compartilhada do servidor Evolution. **Como não quebrar o Fabio:** o canal id 1 já tem `instance`
   nas `credentials` (confirmado), então não depende do fallback — a remoção é segura. (Rede de
   segurança: rodar `msg:channel:sync-env` antes, que garante base/apikey nos canais.)
4. **`GroupNameResolver:64`** — guardar canal null (não chamar `api()` sem canal).
5. (Opcional) `WhatsappSend` (comando legado) — não é caminho de UI; documentar/aposentar.

Ordem sugerida: **1 → 3 → 2 → 4**. A fatia 1 sozinha já estanca o vazamento visível/perigoso; a 3
remove a raiz; a 2 melhora a UX do onboarding; a 4 fecha a ponta de grupo.

---

## Confirmação de que nada foi tocado
- `php artisan test`: **644 verdes** — nada tocado.
- `git status`: limpo, exceto o novo `docs/relatorios/2026-07-03-diagnostico-isolamento-canal.md`.
- Nenhuma migration/schema/escrita; nenhum commit/push.
