# Fatia 22 — Perfis e permissões: enforcement server-side — 2026-07-06

Git no início: HEAD `35b90b2` (fatia 20 — **a Fatia 21 não passou por esta sessão**; baseline
confirmado da 20). Working tree limpo exceto os dois relatórios untracked pré-existentes e um
`public/fundo.webp` untracked (não criado por esta sessão — deixado fora do commit, registrado).
Baseline: **871 verdes / 3457 assertions**.

---

## Mapeamento (lido antes de escrever)

- **Pivot:** `account_user.role` (string **NOT NULL**, `owner|operador`) — existia desde a MT-1 e
  **nunca autorizava nada** (achado da Fatia 2 confirmado). Produção: user 1 (Fabio,
  `is_platform_admin`) = owner da conta 1; user 3 = owner da conta 2. **Zero migration** (campo já
  utilizável).
- **Super-admin:** `is_platform_admin` (não mass-assignable) + middlewares `platform.admin` e
  `require.2fa.admin` no grupo `/admin/*` — **intocados** (não duplicados, não afrouxados).
- **Surpresa positiva:** os **fail-safes de último owner JÁ existiam** no `Admin\Tenants`
  (`alternarOwner` e `removerUsuario` bloqueiam com `ownersCount <= 1` — cobertos por
  `AdminTenantsTest::test_nao_rebaixar_o_ultimo_owner` e `test_remover_usuario_e_bloqueio_do_
  ultimo_owner`). Esta fatia adicionou o lado que faltava por teste: rebaixar **com** outro owner
  presente é permitido. Auto-rebaixamento cai na mesma guarda (é a mesma action).
- **Fallback fase-1** (`tenancy.single_account_fallback`, ON só na suíte legada, OFF em produção):
  usuário sem pivot = o único usuário do sistema fase-1 = tratado como **owner** (semântica
  preservada; em produção o `SetAccountContext` já barra usuário sem vínculo com 403 antes).

## Mapa de permissões final (fonte única: `App\Auth\AreaAccess::MAP`)

| área (rota) | papel mínimo |
|---|---|
| `/admin/*` (Tenants) | **super-admin** (+2FA — mecanismo pré-existente) |
| configuracoes, senhas (cofre), logs | **owner** |
| regras, fluxos, variaveis, conhecimento, campanhas | **owner** — **[A CONFIRMAR pelo dono]**: default seguro (na dúvida, mais restritivo); relaxar pra "operador visualiza" é decisão futura |
| painel, conversas, kanban, contatos, revisao, perfil | operador+ |
| conexao | operador+ — **[A CONFIRMAR]**: o gate `whatsapp.connected` redireciona pra lá quando o canal cai (a secretária precisa reconectar o QR); ações de credenciais têm a rota própria protegida |
| media.* (anexos de conversa) | operador+ (parte de Conversas; escopo por conta já existente) |

## Enforcement (server-side — o núcleo)

1. **Rotas:** middleware novo `account.role` (`EnsureAccountRole`, alias em `bootstrap/app.php`)
   aplicado às 8 rotas owner-only (grupos em `routes/web.php`). Roda após `auth` +
   `SetAccountContext` (conta ATIVA resolvida); super-admin passa; operador em rota owner → **403**
   por URL direta.
2. **Livewire (o buraco clássico):** updates de componente (POST `/livewire/update`) **não passam
   pela rota da página** — `EnsureAccountRole` foi registrado como **middleware PERSISTENTE do
   Livewire** (`Livewire::addPersistentMiddleware`, AppServiceProvider): o Livewire re-aplica a
   checagem (com os parâmetros originais da rota) em todo request subsequente do componente.
3. **Gates de ação (defesa em profundidade):** `AreaAccess::authorizeOwnerAction()` no topo de
   `Senhas::confirmReveal` (revelar segredo) e `Configuracoes::save` — ação forjada por operador =
   403 sem efeito (provado; até com a senha de login correta, o valor nunca entra no componente).
   O gate só age com usuário logado (a suíte legada aciona componentes sem auth; nas rotas reais o
   `auth` é persistente do Livewire).
4. Tudo reusa o padrão existente (`AccountContext`, posse por conta) — nada reinventado.

## Menu (cosmético, por cima)

Filtro no `$nav` do layout consumindo o **mesmo** `AreaAccess::MAP` (fonte única): itens que o
papel não acessa somem. **Sem reagrupar** (Fatia 23). Nota: o link "Configuracoes" do hint do robô
no header continua visível — legítimo (cosmético; clicar dá 403 pela rota).

## Backfill — produção

Comando `msg:backfill-roles` (idempotente): saneia papel vazio → operador; conta sem nenhum owner →
promove o vínculo mais antigo. **Produção: no-op comprovado** (0 saneados, 0 promovidos — as duas
contas já tinham owner; conta 1 = Fabio owner + super-admin), 2ª execução idêntica. **Nota de
schema registrada:** `role` é NOT NULL — "papel null" é impossível; o trabalho real do comando é a
garantia de ≥ 1 owner.

## Ajustes deliberados em testes

**Zero** em testes existentes (o tratamento do fallback fase-1 preservou toda a suíte legada).

## Testes (`RolePermissionsTest`, 11 casos)

- **Rota:** operador barrado por URL nas 8 áreas owner (403); operador acessa as 7 do dia a dia;
  owner acessa tudo da conta; nem operador nem owner acessam `/admin/*`; **super-admin bypassa o
  papel de conta** (vinculado como mero operador, acessa área owner).
- **Ação (forjabilidade):** `confirmReveal` por operador → 403 e `revealedValue` nunca setado;
  `Configuracoes::save` por operador → 403 sem persistir.
- **Papel por conta:** owner em A + operador em B → configuracoes OK com conta ativa A, 403 com B.
- **Menu:** operador não vê Variaveis/Conhecimento; owner vê; dia a dia permanece.
- **Fail-safe:** rebaixar owner **com** outro presente é permitido (o bloqueio do último já
  coberto no `AdminTenantsTest`, referenciado).
- **Backfill:** papel vazio saneado + promoção do vínculo mais antigo; idempotente (2ª execução
  sem mudanças).

## Contagem de testes

| | testes | assertions |
|---|---|---|
| Antes | 871 | 3457 |
| Depois | **882** | **3502** |

Suíte inteira **sequencial**, tudo verde.

## Confirmações explícitas

- **Pipeline/motor/matching: zero diff** (`git diff app/Jobs/ app/Whatsapp/ app/Kanban/` vazio).
  Zero migration. RBAC granular **não** criado; navegação **não** reagrupada (Fatia 23).
- **`queue:restart` executado por precaução** (o `AppServiceProvider` — carregado no boot do
  worker — mudou com o registro do persistent middleware; inócuo pro worker, mas o processo
  longevo recarrega o código novo). Pid/horário na resposta. Workers do Nextgest intocados.
- 2FA/proteção admin intocados; isolamento por conta reforçado (papel por conta, provado).

## Commit
Local, sem push (Fabio empurra). Hash reportado na resposta.
