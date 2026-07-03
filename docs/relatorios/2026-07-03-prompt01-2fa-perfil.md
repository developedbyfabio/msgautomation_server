# Fila noturna — Prompt 01: 2FA (TOTP/Fortify) + página Perfil — 2026-07-03

Status: **VERDE. 554/554 (baseline 544/544).** Webhook vivo e canal Evolution intocados.

## O que mudou

**Parte A — 2FA (Fortify v1.36, nativo):**
- `laravel/fortify` instalado; **só a feature `twoFactorAuthentication`** habilitada
  (`confirm: true` + `confirmPassword`), `views: false` — registro/reset/perfil do
  Fortify NÃO existem; o login Livewire/Flux atual continua o mesmo.
- Migration ADITIVA publicada do Fortify (`two_factor_secret`, `two_factor_recovery_codes`,
  `two_factor_confirmed_at` em users). Nota: o `down()` do stub oficial dropa as colunas —
  nunca rodar rollback em produção (regra da casa).
- `User` ganhou `TwoFactorAuthenticatable`.
- **Login integrado**: com credenciais válidas E 2FA confirmado, o Livewire Login NÃO
  autentica — grava o desafio na sessão (mesmas chaves `login.id`/`login.remember` do
  pipeline do Fortify) e cai em `/two-factor-challenge`. Sem 2FA, fluxo idêntico ao de
  sempre (provado por teste).
- Tela `/two-factor-challenge` própria (GET nosso, visual Flux igual ao login; sem
  desafio pendente → volta pro login). O POST é o do Fortify (`two-factor.login.store`)
  com **throttle 5/min** (limiter `two-factor` por `login.id`) — 6ª tentativa = 429.
  Recovery code aceito no mesmo form (toggle Alpine) e ROTACIONADO após uso.
- 2FA **opt-in**: nasce desligado; ativar/desativar/regenerar exigem a senha atual.

**Parte B — /perfil (Livewire/Flux, item "Perfil" no menu):**
- Dados do usuário logado; **trocar e-mail** (senha atual + formato + unicidade);
  **trocar senha** (atual + nova com mín. 8, letras e números + confirmação);
  **seção 2FA** (ativar → QR + chave manual → confirmar código → recovery codes na tela
  uma única vez → regenerar/desativar com senha).
- MT-1: a página só lê/escreve `auth()->user()` — sem id vindo da tela; teste de
  isolamento prova que B não vê nem afeta A. `TenantIsolationTest` intacto.

## Decisões/registros técnicos
- Fortify também registra `POST /logout` com o mesmo nome do nosso — inofensivo hoje,
  MAS **`route:cache` passaria a falhar por nome duplicado** (não usamos; fica o aviso).
- Aprendizados de teste que viraram robustez de código: ações do Perfil usam
  `auth()->user()->fresh()` (guard pode segurar instância stale) e `resetErrorBag()` no
  início de cada ação (Livewire persiste erros entre requests — erro velho poluía o
  submit seguinte). Anti-replay do Fortify documentado no helper de teste (código de
  confirmação usa o slice de tempo anterior pra não colidir com o challenge).

## Testes (10 novos — PerfilE2faTest)
Ativação com QR/senha; challenge no login (válido passa, inválido barra, sem 2FA segue
direto); recovery code autentica e rotaciona; rate limit 429; desativar/regenerar com
senha; e-mail (senha/formato/unicidade); senha (atual/força/login com a nova);
isolamento entre usuários. **Suíte completa: 554/554 (2.009 assertions).**
Smoke pós-deploy: login 200, challenge sem sessão → redirect login, webhook Evolution 401
de token inválido (intocado), serviços ativos.

## Como o Fabio ativa o 2FA dele
1. `painel.nextgest.com.br` → menu **Perfil** → seção "Verificação em duas etapas".
2. Digita a senha atual → **Ativar 2FA** → escaneia o QR no Google Authenticator/1Password.
3. Digita o código de 6 dígitos → **Confirmar e ligar** → **salva os códigos de
   recuperação** que aparecem (única exibição; cada um vale uma vez).
4. No próximo login: e-mail+senha → tela do código → entra. Sem o celular, usar um
   recovery code no link "Usar um código de recuperação".
