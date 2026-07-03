# Prompt 27 — Isolar o canal por tenant (status + ações + raiz do fallback) — 2026-07-03

**Status: ENTREGUE (4 fatias, ordem 1 → 3 → 2 → 4).** Baseline 644 → **657 verdes**,
`TenantIsolationTest` 28 verde em cada fatia. Sem migration. Envio/recebimento/reativo intocados.
Canal do Fabio (id 1) não regride: resolve via `credentials` do canal, não via fallback.

Hashes: **Fatia 1 `9a5dd8f` · Fatia 3 `e6b20c0` · Fatia 2 `defe48c` · Fatia 4 `da78225`**.

## Fatia 1 — `9a5dd8f` — StatusConexao escopado + guarda de canal null
`app/Livewire/StatusConexao.php`:
- `canal()` (:31) agora `Channel::defaultFor(accountId())` (escopado; null se a conta não tem canal).
- Guarda null nos 3 pontos: `refresh()` (:38 connectionState), `disconnectConfirmed()` (:97 logout),
  `abrirQr()` (:123 connect) — **não** chamam `provider->api()` sem canal. Estado `sem_canal`; o
  header mostra "sem canal / Conectar" (link pra `/conexao`). `abrirQr` sem canal redireciona pra
  `/conexao`. Elimina o **display leak** (header mostrava o estado do `fabio-pessoal`) **e** os
  **action leaks** (logout/QR na instância global).
- `status-conexao.blade.php`: estado `sem_canal` + ação "Conectar" (link, não `abrirQr`).
- Conta COM canal: inalterado.

## Fatia 3 — `e6b20c0` — Remove o fallback de INSTÂNCIA global no `.env` (a raiz)
`app/Channels/Evolution/EvolutionProvider.php::credentialsFor()` (:70): a instância é **sempre** a do
canal — `($cred['instance'] ?? null) ?: ($channel?->instance ?? '')`. **Removido** o
`?: config('services.evolution.instance')`. Sem canal → instância vazia (no-op explícito), **nunca**
`fabio-pessoal`. `base_url`/`apikey` seguem do env (infra compartilhada do servidor Evolution).
Confirmado: o canal id 1 tem `instance` nas credentials → resolve via canal, **sem** depender do
fallback (comportamento preservado).

## Fatia 2 — `defe48c` — Gate manda conta SEM canal pra /conexao
`app/Http/Middleware/EnsureWhatsappConnected.php`: conta **sem canal** (`Channel::defaultFor` null)
**ou** canal `disconnected` → `redirect('conexao')`. Sem contexto (bootstrap) → passa.
**Sem loop:** o middleware só está no grupo `whatsapp.connected` (painel/conversas/kanban/contatos/
regras/revisao/campanhas/configuracoes); `/conexao`, `/perfil`, `/admin/*`, `/senhas`, `/logs`,
`/fluxos`, `/conhecimento`, `/variaveis` estão **fora** — sem redirect circular.
Ajuste de testes: `AuthTest`/`NavegacaoSidebarTest` passaram a provisionar um **canal conectado** no
setup (conta onboarded) — refletem o novo gate. `GateSemCanalTest` cobre: sem canal → /conexao;
com canal → 200; desconectado → /conexao; /conexao e /perfil sem loop.

## Fatia 4 — `da78225` — GroupNameResolver (ponta de grupo)
`app/Whatsapp/Groups/GroupNameResolver.php::resolveNow()`: conta sem canal → **no-op** (return null),
não chama `provider->api()` — nunca busca metadado de grupo na instância global. Testes GroupName
ajustados (contas com canal, como no uso real) + teste novo do no-op sem canal.

## GATE (confirmado por teste)
- **Isolamento de canal:** `StatusConexaoIsolamentoTest::test_isolamento_B_sem_canal_nao_toca_o_canal_de_A`
  — tenant A com canal (`fabio-pessoal`) + tenant B sem canal; logado em B, status = `sem_canal` e
  `refresh`/`disconnectConfirmed` **não enviam nada** (`Http::assertNothingSent`); o canal de A fica
  `connected` intacto.
- **Nenhuma leitura de canal cai mais na instância global:** Fatia 3 (raiz) + guardas de null nas
  Fatias 1/4; `credentialsFor(null)` retorna instância vazia (teste
  `CredenciaisSemFallbackGlobalTest`).
- **Contas com canal não regridem:** status/logout/connect (Fatia 1), envio/recebimento (intocados),
  gate (passa com canal conectado). Canal do Fabio resolve via credentials.
- `TenantIsolationTest` 28 verde nas 4 fatias.

## O que NÃO foi tocado
Envio (`Sender`, `SendAutoReply` usa `$incoming->channel`), recebimento (webhook routing por
token/instância), pipeline reativo — o diagnóstico já os confirmou isolados; nenhuma linha alterada.
Sem migration. `base_url`/`apikey` do env mantidos como infra compartilhada (decisão do diagnóstico).

## Resultado
Um tenant novo sem canal agora vê **"sem canal"** (não o WhatsApp do Fabio), é levado a **/conexao**
pra conectar o próprio número, e **nenhuma** ação dele (status/logout/QR/grupo) toca a instância de
outra conta. Status, envio e recebimento estritamente por conta. Suíte **657 verdes**.
