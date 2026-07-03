# Prompt 24b — Form de credenciais Cloud na UI + webhook base em config — 2026-07-03

**Status: ENTREGUE.** Baseline 632 → **638 verdes** (+6), `TenantIsolationTest` 28. Sem migration.
Consome `SaveCloudChannel` (24a) — não reimplementa validação/anti-swap/cifra. Provider/webhook/
reativo intocados.

## Passo 1 — Webhook base em config (fonte única, URL idêntica)
- Nova config `services.cloud_api.webhook_base` = `env('CLOUD_API_WEBHOOK_BASE', 'https://wa.nextgest.com.br')`
  — **default = valor histórico**, pra a URL não mudar. Documentada no `.env.example`.
- Helper único `Channel::cloudCallbackUrl()` = `webhook_base` + `/webhook/cloud/{token}`. O comando
  `msg:channel:create-cloud` passou a usá-lo (era hardcoded na linha 111). **NÃO** usei
  `route('webhook.cloud')` — resolveria pro APP_URL (`painel.*`), não pro subdomínio do webhook
  (`wa.*`); o 24a já tinha confirmado essa divergência.
- **URL idêntica confirmada:** `cloudCallbackUrl()` → `https://wa.nextgest.com.br/webhook/cloud/{token}`
  (igual ao valor de hoje). Caracterização do 24a (`CloudChannelSaveTest`) segue **verde**.

## Passo 2 — Form de credenciais Cloud (`/conexao`)
`Conexao` (Livewire) + `conexao.blade.php`: botão "Conectar via API oficial (Cloud)" abre um
`x-modal` com os campos do Action (phone_number_id, waba_id, access_token, app_secret, verify_token).
`salvarCloud()` resolve **update** (conta já tem canal cloud com esse phone_number_id) vs **create**
e chama `SaveCloudChannel::handle($contaAtiva, $input, $update)`, apresentando o `SaveCloudChannelResult`:
- `error` (inclui o anti-swap do verify "EAA") → banner de erro no form, **nada persiste**.
- `warning` (access_token sem "EAA") → banner de aviso, não bloqueia.
- `verifyGerado` → mostra o verify gerado **uma vez** (pra colar na Meta); informado/mantido → mascarado.
- **Sucesso:** exibe a **Callback URL** (`cloudCallbackUrl()`, base da config + token) e o verify,
  com instrução de configurar o webhook na Meta (assinar `messages`).

## Passo 3 — Segurança das credenciais (confirmado por teste)
- `access_token`/`app_secret`/`verify` com `wire:model` **deferido** (Livewire 3): só vão ao servidor
  no `salvarCloud`, e são **resetados imediatamente após** — nunca ecoados de volta no snapshot.
- Após salvar, o access token aparece **só mascarado** (`configurado (…1234)`); nunca em texto
  (teste `test_seguranca_access_token_nao_fica_no_estado_apos_salvar`).
- **Nunca logado** (não há Log de credencial; o toast não inclui segredo). Persistência só cifrada
  (`encrypted:array`, garantido pelo Action; teste confirma cifrado em repouso).
- Form lê/edita só a conta ativa: `abrirCloud` pré-preenche **só phone/waba** (não segredos) do canal
  cloud **da conta ativa**.

## GATE (confirmado por teste)
- **Escopo à conta ativa:** `salvarCloud` usa `Account::find(AccountContext::id())`; teste
  `test_isolamento_form_so_ve_e_cria_na_conta_ativa` prova que a conta A não vê/edita o canal Cloud
  da B (abrirCloud não pré-preenche com dados da B; create vai só em A; B intacto).
- **Provider/webhook/reativo intocados** (`git diff`: config, comando, Channel helper, Conexao+blade,
  .env.example — nada de `CloudApiProvider`/`ChannelWebhookController`).
- **Webhook base resolve pro valor atual** (URL idêntica → webhook Cloud do tenant vivo não quebra).
- `TenantIsolationTest` 28 verde.

## Testes (638 verdes, +6)
`ConexaoCloudFormTest`: cria canal Cloud cifrado+escopado; anti-swap "EAA" → erro sem persistir;
update sem duplicar (preserva webhook_token); segurança (token só mascarado, resetado do estado);
webhook URL = base da config + token (== helper do model); isolamento conta A/B.
Caracterização do 24a e `CloudApiProviderTest` seguem verdes (URL idêntica).

## Checklist manual (Fabio)
- [ ] Tenant em `/conexao` → "Conectar via API oficial (Cloud)" abre o form.
- [ ] Salvar com credenciais válidas → mostra Callback URL (`wa.nextgest.com.br/...`) + verify.
- [ ] verify começando com "EAA" → erro claro, nada salvo.
- [ ] Reabrir o form (conta com canal Cloud) → phone/waba pré-preenchidos, segredos em branco.
- [ ] Token nunca aparece em texto depois de salvo (só "configurado (…1234)").
- [ ] O canal Cloud do tenant de teste (Fabio) segue funcionando (URL de webhook inalterada).
