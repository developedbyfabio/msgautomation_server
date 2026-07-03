# Prompt 25 — Admin de tenants: edição de conta e gestão de usuários — 2026-07-03

**Status: ENTREGUE.** Baseline 638 → **644 verdes** (+6), `TenantIsolationTest` 28. Sem migration
(usa Account/User + pivot `account_user`). Provider/webhook/reativo/isolamento intocados.

## Passo 1 — Achados
1. **Slug é imutável (routing):** o nome da instância Evolution `conta-{id}-{slug}` é calculado de
   `Str::slug($account->name)` **no provisionamento** e **congelado** em `channels.instance`
   (`ChannelProvisioner:107-113`). Renomear a conta depois **não** recalcula/muda a instância. Logo,
   a edição altera **só o nome de exibição**; o slug mostrado no detalhe é a **instância real do
   canal** (imutável, read-only) — ou, sem canal, um derivado informativo.
2. **Usuário/role:** vínculo no pivot `account_user.role` (`owner|operador`) via
   `accounts()->syncWithoutDetaching([id => ['role' => ...]])`; senha `Hash::make` (cast `hashed`).
   Reusei esse mecanismo (mesmo do `CreateTenant`/`user:create`), sem duplicar.
3. **`is_platform_admin`** confirmado fora do `#[Fillable(['name','email','password'])]`.

## Passo 2/3 — O que a edição permite (`/admin/tenants`, botão "Editar")
Modal de edição do tenant (só Account + User):
- **Renomear a conta** (`salvarConta`): nome obrigatório, único (ignorando a própria). **Slug read-only**
  (mostra a instância congelada do canal).
- **Listar usuários** do tenant (nome, email, role).
- **Adicionar usuário** (`adicionarUsuario`): nome, email (único), senha (min 10, hasheada), checkbox
  **Owner** → grava role owner|operador. Resolve o tenant "T" órfão (dar-lhe um owner pela UI).
- **Editar email** (`editarEmail`): único (ignorando o próprio).
- **Resetar senha** (`resetarSenha`): super-admin define nova senha (min 10, hasheada; sem senha antiga).
- **Definir/alterar owner** (`alternarOwner`): alterna owner↔operador no pivot.
- **Remover** (`removerUsuario`): **detach** do tenant (não apaga o usuário globalmente — pode estar
  em outros tenants; não-destrutivo).

## GATE (confirmado por teste)
- **`platform.admin`:** a rota já é gated (prompt 22); comum → 403 (teste existente).
- **`is_platform_admin` NÃO editável pela UI:** não há campo nem método que o setar; usuários criados
  nascem `false` (teste `test_adicionar_owner_a_tenant_sem_usuarios` afirma `is_platform_admin=false`).
  Sem caminho de escalonamento.
- **Sem tenant órfão:** `removerUsuario` e `alternarOwner` **bloqueiam** o último owner (contagem de
  owners; toast de erro claro) — testes `test_remover_usuario_e_bloqueio_do_ultimo_owner` e
  `test_nao_rebaixar_o_ultimo_owner`.
- **Só Account + User; sem dados escopados:** o componente lê nome/email/role e conta de usuários —
  nunca contatos/mensagens. `usuarioDoTenant()` garante que a operação é sobre usuário **do tenant em
  edição** (404 cross-tenant).
- **Escopo por tenant:** editar A não afeta B (`test_gestao_de_usuarios_de_a_nao_afeta_b`: B intacto,
  a lista de A não mostra usuário da B). `TenantIsolationTest` 28 verde.

## Passo 4 — Segurança de credenciais
Senha nunca exibida; reset só define nova (hasheada, `Hash::make`); **nunca logada** (sem Log de
senha; toast sem segredo). Slug/instância nunca alterados pela edição.

## Testes (644 verdes, +6)
`AdminTenantsTest` (novos): renomear não muda o slug/instância; adicionar owner ao "T" (hasheada,
não vira super-admin, count=1); editar email + resetar senha (nova funciona, antiga não); remover
usuário + bloqueio do último owner; não rebaixar o último owner; isolamento A/B. Os testes do
prompt 22 (acesso 403/criar/validação/isolamento) seguem verdes.

## Fora de escopo (não feito, como instruído)
Excluir/desativar tenant (destrutivo, decisão à parte). O tenant "T" órfão **pode agora ser
resolvido pela UI** (adicionar um owner) — não criei usuário em produção por conta própria (senha é
decisão do Fabio); a capacidade está pronta na tela.
