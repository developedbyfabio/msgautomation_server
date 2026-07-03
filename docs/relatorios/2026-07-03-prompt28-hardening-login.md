# Prompt 28 — Endurecer o login antes de remover o Cloudflare Access — 2026-07-03

**Status: ENTREGUE.** Baseline 657 → **661 verdes** (+4), `TenantIsolationTest` 28. Sem migration.
Login e 2FA (Fortify) não regridem — só reforço.

## Passo 1 — Achados (o que já estava coberto)
- **Login:** componente **custom** Livewire (`app/Livewire/Login.php`), não o `/login` do Fortify. Já
  tinha rate limit próprio: `RateLimiter` por **(email+IP)**, 5 tentativas, decay 60s (`hit($key,60)`
  → efetivo 5/min). O 2FA é delegado ao Fortify (`/two-factor-challenge`).
- **Anti-enumeração:** já OK — `'Credenciais invalidas.'` idêntica para email inexistente e senha
  errada (`Auth::attempt` falha nos dois casos com a mesma mensagem).
- **Reset de senha:** **desabilitado** — `config/fortify.php` `features` só tem
  `twoFactorAuthentication`; não há `Features::resetPasswords()` nem rotas de reset. A troca de senha é
  no `/perfil` (autenticado, exige senha atual). Logo, **não há fluxo público de reset** pra virar
  vetor de brute force/enumeração — nada a endurecer lá.
- **2FA (TOTP):** funcional e testado — `PerfilE2faTest` cobre ativar (exige senha, QR, confirmar com
  código), challenge no login, recovery code rotacionado, **rate limit do challenge** (limiter
  `two-factor` = 5/min por `login.id`, em `FortifyServiceProvider`), desativar, regenerar codes.
- Fortify tem `RateLimiter::for('login', 5/min por email+ip)` e `for('two-factor', 5/min)` — o
  `two-factor` protege o desafio; o `login` do Fortify não é usado (login é custom).

## Passo 2/3 — O que foi reforçado
Único gap real: o freio por **(email+IP)** não corta **password-spraying** (um IP tentando MUITOS
emails distintos — cada email tem seu próprio contador). Adicionei um **segundo freio por IP** no
`Login::login()`:
- `login:{email}|{ip}` → **5/min** (protege UMA conta) — já existia.
- `login-ip:{ip}` → **20/min** (novo) — corta spraying de vários emails do mesmo IP.
- Ambos incrementados em falha (`hit(...,60)`); na falha, mensagem **genérica** mantida
  (`'Credenciais invalidas.'`); no bloqueio, `'Muitas tentativas. Tente de novo em Ns.'`. No sucesso,
  limpa só o freio da conta (o de IP decai sozinho — integridade do anti-spray).

Anti-enumeração: confirmada e travada por teste (mesma mensagem nos dois casos). Reset: N/A (desabilitado).

## Passo 4 — 2FA
Funcional e acessível no `/perfil` (opt-in), coberto pelo `PerfilE2faTest`. **Não** forcei ativação.
**Recomendação (decisão do Fabio, fatia futura):** tornar 2FA **obrigatório para super-admin**
(`is_platform_admin`), dado o acesso cross-tenant da tela `/admin/tenants` — um super-admin sem 2FA é
o maior risco quando o Access sair. Sugestão de implementação futura: um middleware que, para
platform-admin sem 2FA habilitado, força o setup antes de liberar `/admin/*`.

## GATE (confirmado por teste)
- Login válido funciona (`test_login_valido_funciona_nao_regride`, `AuthTest`) — não regride.
- N erradas → bloqueio com mensagem clara (`test_muitas_tentativas_erradas_bloqueia`).
- Email inexistente e senha errada → **mesma** mensagem (`test_anti_enumeracao...`).
- Freio por IP corta spraying: 20 tentativas de um IP → próxima barrada mesmo com senha certa
  (`test_freio_por_ip_corta_password_spraying`), e continua deslogado.
- 2FA (setup + challenge + rate limit) intacto (`PerfilE2faTest`, 11 testes verdes).
- `TenantIsolationTest` 28 verde; nada cross-tenant.

## Testes (661 verdes, +4)
`LoginHardeningTest`: login válido; throttle por conta+IP; anti-enumeração; freio por IP (spraying).

## Lembrete de operação (fora deste código)
- **Remoção do Cloudflare Access do painel:** feita no dashboard do Cloudflare (**Zero Trust → Access
  → Applications**), removendo/desabilitando a Application que protege `painel.nextgest.com.br`. Com o
  login endurecido, o painel fica protegido pela própria auth da aplicação.
- **Não afetar o webhook:** `wa.nextgest.com.br` (Callback URL do Cloud API) **não** deve ser tocado —
  é outra Application/rota; o webhook valida origem por token/HMAC, independente do Access.
- Após remover o Access, validar: login exige credenciais, 2FA (se ativo) pede código, e o webhook
  Cloud continua recebendo (mensagem de teste chega).
