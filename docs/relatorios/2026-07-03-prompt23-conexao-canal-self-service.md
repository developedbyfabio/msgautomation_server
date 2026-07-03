# Prompt 23 — Onboarding Fatia 3: conexão de canal self-service (Evolution) — 2026-07-03

**Status: ENTREGUE.** Baseline 619 → **622 verdes** (2327 assertions), `TenantIsolationTest` 28.
Sem migration. Reusa o `ChannelProvisioner` (não duplica). Canal existente e pipeline reativo
intocados.

## Passo 1 — Achados
1. **Provisioner reusado:** `evolution:setup --account=` chama `ChannelProvisioner::provision($account)`
   (`app/Console/Commands/EvolutionSetup.php:33`). É **idempotente**: se a conta já tem canal
   (`Channel::defaultFor`), reusa; se a instância já existe na Evolution (`instanceExists`), não
   recria; webhook vivo divergente fica intocado. A tela chama **o mesmo** `provision()`.
2. **QR do canal existente:** `Conexao::poll()`/`gerarQr()` usam `provider->api($canal)->connectionState()`
   e `->connect()` (base64 do QR). Reusados após criar o canal.
3. **Estado "tem canal":** `Channel::defaultFor($accountId)` (escopado por conta, `withoutAccountScope
   ()->where('account_id')`). **Bug latente encontrado:** a `canal()` antiga era
   `Channel::query()->oldest()`; com conta sem canal retornava null e o `provider->api(null)` caía no
   **fallback do `.env`** (instância do Fabio). Corrigido: `canal()` agora usa `defaultFor(accountId)`
   e a tela **não faz poll/QR sem canal**.

## Passo 2 — Tela self-service (`/conexao`, escopada à conta ativa)
`app/Livewire/Conexao.php` + `resources/views/livewire/conexao.blade.php`:
- **Conta SEM canal:** `mount()` seta `temCanal=false`, **não** faz poll/QR (evita o fallback do
  env). A tela mostra o botão **"Conectar WhatsApp"**. Clique → `conectar()`:
  resolve a **conta ativa** (`AccountContext::id()` → `Account::find`), chama
  `ChannelProvisioner::provision($account)` (cria instância `conta-{id}-{slug}` + token + webhook),
  seta `temCanal=true`, toast, e faz `poll()` → gera o QR. Erro de provisionamento vira toast +
  `provisionError` (best-effort, não quebra a tela).
- **Aguardando scan:** mostra o QR (mecanismo atual) + estado; `wire:poll.5s` atualiza e redireciona
  pras conversas ao conectar.
- **Conta COM canal / conectado:** comportamento atual (poll/QR/estado), **sem recriar nada**.

## GATE (confirmado por teste)
- **Só a conta ativa:** `provision()` recebe `Account::find(AccountContext::id())` — nunca outra
  conta. Teste `test_provisionamento_escopado_a_conta_ativa_nao_toca_outra_conta`: contexto A cria
  canal só em A; B continua sem canal.
- **Idempotência:** `conectar()` faz no-op de criação se `canal() !== null` (só segue pro QR).
  Teste `test_conta_com_canal_nao_recria_instancia`: 1 canal, token intacto, sem duplicata.
- **Canal existente/reativo intocados:** o fluxo novo só cria quando não há canal; `poll()` guarda
  contra `temCanal=false` (não cai no env). Provisioner não foi alterado.

## Testes (622 verdes, +3)
`ConexaoSelfServiceTest` (Evolution HTTP mockado por endpoint): conta sem canal conecta →
provisiona (instância `conta-{id}-`, token, provider evolution) + QR disponível; conta com canal não
recria; provisionamento escopado à conta ativa (não toca outra). `ConexaoTest` legado e
`TenantIsolationTest` seguem verdes.

## Fora de escopo (próxima fatia)
Credenciais Cloud pela UI (esta fatia é Evolution). E, do prompt 21, o super-admin "puro" sem tenant
(isentar `/admin/*` do SetAccountContext) — não relacionado a esta tela.

## Checklist manual (Fabio)
- [ ] Tenant novo (owner recém-criado, sem canal) em `/conexao`: aparece "Conectar WhatsApp".
- [ ] Clicar cria a instância da conta e mostra o QR; escanear conecta e segue pras conversas.
- [ ] Tenant que já tem canal: fluxo de QR/estado normal, sem recriar.
- [ ] O canal do tenant de teste (Fabio) segue intacto.
