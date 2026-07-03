# Prompt 29 — 2FA obrigatório para super-admin em /admin/* — 2026-07-03

**Status: ENTREGUE.** Baseline 661 → **666 verdes** (+5), `TenantIsolationTest` 28. Sem migration
(reusa o `two_factor` do Fortify). Login/2FA existentes não regridem; tenants comuns não são afetados.

## Passo 1 — Achados
1. **Critério de "2FA ativado":** `$user->hasEnabledTwoFactorAuthentication()` (Fortify). Com
   `confirm=true` (config deste app), exige **`two_factor_secret` E `two_factor_confirmed_at`** —
   ou seja, 2FA de fato confirmado (secret sem confirmar não conta). É o mesmo método que o Login e o
   Perfil já usam.
2. **`/admin/*`** aplica `platform.admin` (prompt 22) em `routes/web.php:98`. Somei a checagem de 2FA
   no **mesmo grupo**.
3. **Perfil:** rota `/perfil` (name `perfil`), **fora** de `/admin/*` — destino seguro do redirect,
   sem loop.

## Passo 2 — Middleware
`app/Http/Middleware/EnsureTwoFactorForPlatformAdmin.php` (alias `require.2fa.admin`): se o usuário é
`is_platform_admin` **e** não tem 2FA confirmado → `redirect()->route('perfil')->with('aviso', ...)`.
Quem não é super-admin **passa reto** aqui (o 403 é do `platform.admin`) — order-independente.
Aplicado no grupo `/admin/*`: `->middleware(['platform.admin', 'require.2fa.admin'])`.

Fluxos:
- **super-admin sem 2FA** → `/perfil` com aviso "Ative o 2FA para acessar a administração da plataforma."
  (banner âmbar no topo do Perfil, via `session('aviso')`).
- **super-admin com 2FA** → passa.
- **usuário comum** → 403 (pelo `platform.admin`, inalterado).

## GATE (confirmado por teste)
- **Tenant comum sem 2FA acessa o painel normal:** `test_tenant_comum_sem_2fa_acessa_o_painel_normal`
  (`/perfil` → 200). A obrigatoriedade **não vaza** pra fora de `/admin/*` — só age quando
  `is_platform_admin` numa rota `/admin/*`.
- **Sem loop:** `/perfil` acessível pro super-admin sem 2FA (`test_sem_loop_perfil_acessivel...`).
- **Login/2FA não regridem:** `PerfilE2faTest` (11) e `LoginHardeningTest`/`AuthTest` verdes.
- `TenantIsolationTest` 28 verde.

## Testes (666 verdes, +5)
`AdminDoisFatoresTest`: super-admin sem 2FA → /perfil (+aviso); com 2FA → /admin ok; comum → 403;
tenant comum sem 2FA → painel normal; sem loop (/perfil acessível). Ajuste em `AdminTenantsTest`
(`test_super_admin_acessa...`): o admin do teste HTTP agora habilita 2FA antes do `assertOk` (reflete
o novo requisito); os demais testes de admin usam `Livewire::test` (não passam pelo middleware HTTP),
inalterados.

## Nota operacional (o Fabio)
O Fabio ainda **não ativou** o 2FA dele. Após esta fatia, ao abrir `/admin/tenants` ele será levado ao
`/perfil` com o aviso — basta **ativar o 2FA** (opt-in, QR + confirmar código) e o `/admin` libera.
Comportamento esperado, não é bug.
