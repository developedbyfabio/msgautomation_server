# Prompt 22 — Admin de tenants (super-admin) — 2026-07-03

**Status: ENTREGUE.** Baseline 614 → **619 verdes** (2311 assertions), `TenantIsolationTest` 28.
Migration aditiva (flag), gate, tela, Action reusável e testes. Isolamento de tenant intacto.

## Passo 1 — Achados (antes de codar)
1. **Flag de admin no User:** NÃO existia. Criada `users.is_platform_admin` (boolean default false),
   **fora do `#[Fillable]`** (não mass-assignable — só vira true via seed/tinker, evita escalonamento
   por form). Cast boolean no model.
2. **Account/User são transversais** (não usam `BelongsToAccount`): criar Account/User **não**
   esbarra no high-fail-sem-contexto. `Account::booted()` provisiona board + variáveis com **id
   explícito** (`BoardProvisioner::ensureDefaultBoard(int)`, `VariableProvisioner::ensureSystemVariables(int)`),
   sem depender de `AccountContext`. **Nenhum refactor de AccountContext foi necessário.**
3. **Lógica do `user:create`:** cria User com `Hash::make` e vincula `syncWithoutDetaching([id =>
   ['role' => 'owner']])`. Extraída num Action reusável (`App\Actions\CreateTenant`) que a tela consome.

Nota de autorização: o super-admin precisa ter **um vínculo de tenant** (o `SetAccountContext`,
global no `web`, roda antes e — em produção com fallback off — 403 usuário logado sem vínculo). O
Fabio já é owner do tenant de teste, então passa. Um super-admin "puro" (sem tenant) exigiria isentar
`/admin/*` do SetAccountContext — anotado como follow-up; **não** feito (evita refactor às cegas).

## Passo 2 — Super-admin
- Migration `2026_07_03_090000_add_is_platform_admin_to_users` (aditiva, default false).
- Middleware `EnsurePlatformAdmin` (alias `platform.admin`): **order-independente** — sem usuário,
  passa adiante (o `auth` redireciona pro login, não 403 num guest); só bloqueia (403) **usuário
  logado que não é super-admin**.
- **Usuário do Fabio marcado como super-admin** (produção): via tinker pontual —
  `User::where('email','fabio9384@gmail.com')->first()->forceFill(['is_platform_admin'=>true])->save();`
  (não-hardcode; `forceFill` porque a flag está fora do fillable). Para marcar outro no futuro,
  mesmo comando com o email.

## Passo 3 — Tela
- Rota `GET /admin/tenants` (`name admin.tenants`), dentro do grupo `auth` + `platform.admin`, **fora**
  do gate `whatsapp.connected` (não depende de canal). Componente `App\Livewire\Admin\Tenants`.
- **Lista** (transversal, cross-tenant proposital só pro super-admin): nome, slug derivado
  (`conta-{id}-{slug}`), nº de usuários (`withCount('users')`), data de criação (SP). **Sem** dado
  escopado.
- **Criar** (`x-modal`, padrão do app): nome da conta + owner (nome/email/senha). Chama
  `CreateTenant` (transação: Account → owner com senha hasheada → vínculo role owner). Validação:
  `accountName` único em `accounts`, `ownerEmail` único em `users`, senha min 10. Toast no padrão.
- **Nav:** item "Tenants" na sidebar **só** pra `is_platform_admin` (senão a tela não teria porta).

## GATE de segurança (confirmado por teste)
- Super-admin acessa (200); **usuário comum logado → 403**; deslogado → redirect login.
- A tela cria/lista **só a estrutura** (Account + User transversais). Não acessa/expõe dado escopado —
  teste prova que o número de um contato de tenant **não aparece** na tela (`assertDontSee('5541…')`).
- Criar 2º tenant não vaza pro 1º (contatos de A ficam em A, B nasce vazio). `is_platform_admin`
  não é setado pra owners criados pela tela. `TenantIsolationTest` (28) não regrediu.

## Testes (619 verdes, +5)
`AdminTenantsTest`: acesso (403 comum / redirect deslogado / 200 admin); criar tenant (Account com
board+variáveis + owner vinculado com senha hasheada); validação (conta/email duplicados + senha
curta); owner vinculado **só** à própria conta; isolamento (2º tenant não vaza + tela não expõe
escopado). `NavegacaoSidebarTest` segue verde (item admin oculto pro não-admin).

## Arquivos
Migration; `User` (cast); `EnsurePlatformAdmin` + alias em `bootstrap/app.php`; `CreateTenant`
(Action); `Admin\Tenants` + blade; `routes/web.php`; item de nav no layout; `AdminTenantsTest`.

## Operação
- Marcar super-admin: tinker `->forceFill(['is_platform_admin'=>true])->save()` no email desejado.
- Acesso: sidebar "Tenants" (só admin) ou `/admin/tenants`.
- **Próxima fatia** (do diagnóstico prompt 21): UI de conexão de canal self-service (o
  `ChannelProvisioner` já faz o backend); e, se quiser super-admin sem tenant, isentar `/admin/*`
  do `SetAccountContext`.
