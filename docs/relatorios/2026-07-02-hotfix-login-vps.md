# HOTFIX — Login do painel falhava no VPS (pós-cutover) — 2026-07-02

Status: **CORRIGIDO no servidor; aguardando confirmação do Fabio no browser.**

## Sintoma exato (capturado server-side, sem browser)

- `storage/logs/laravel.log`: NENHUM erro de produção (só entradas `testing.` da suíte)
  — as tentativas do Fabio não geravam erro no servidor.
- Reprodução local (curl/python contra 127.0.0.1:8080, bypass do túnel):
  - GET `/login` 200; cookie `msgautomation-session` gravado; sessão PERSISTE
    (2 GETs = mesmo CSRF `data-csrf`, mesma linha na tabela `sessions`: 258→259→259).
  - Submit REAL do Livewire (snapshot da página + `calls: login`, e-mail correto +
    senha errada de propósito) → **200 com "Credenciais invalidas"** e chave do
    RateLimiter gravada no Redis 6380 db1. **Local: sessão, CSRF, Livewire, Auth e
    Redis 100% funcionais.**
- Ou seja: sintoma classe **(e)** — funciona local, falha pelo domínio → causa na
  camada proxy/HTTPS.

## Causa raiz (com evidência)

**TrustProxies ausente** no `bootstrap/app.php` (Laravel 13, `withMiddleware`).
O cloudflared entrega os requests em HTTP no loopback com `X-Forwarded-Proto: https`;
sem confiar no proxy, o app se acha em HTTP e gera URLs absolutas `http://`.
Evidência — request simulado do túnel (`Host: painel.nextgest.com.br` +
`X-Forwarded-Proto: https`) ANTES da correção:

    http://painel.nextgest.com.br/livewire-945a02c0/update   <- endpoint do submit
    http://painel.nextgest.com.br/build/assets/app-*.css/js
    http://painel.nextgest.com.br/livewire-945a02c0/livewire.min.js

Numa página HTTPS o browser bloqueia o fetch `http://` como **mixed content** →
"clico em Entrar e nada acontece". (A página renderizava porque o rewrite automático
do Cloudflare cobre tags HTML; a URI embutida no config JS do Livewire não.)
Coadjuvantes descartados: config cache inexistente (`bootstrap/cache/` só packages/
services), Redis 6380 PONG, permissões ok (tudo root e o serve roda como root),
usuário `sistema5@engepecas.com.br` existe com hash bcrypt válido (não tocado),
Access cobre o hostname inteiro (inclui `/livewire-*`).

## O que mudou (tudo reversível)

1. `bootstrap/app.php`: `$middleware->trustProxies(at: ['127.0.0.1'])` — o único
   proxy possível é o cloudflared local (8080 não é exposta; ufw só deixa a subnet
   Docker da Evolution). Headers X-Forwarded-* padrão passam a ser honrados.
2. `.env`: `APP_URL` `http://127.0.0.1:8080` → `https://painel.nextgest.com.br`;
   `SESSION_SECURE_COOKIE=true` (novo). `SESSION_SAME_SITE` segue default `lax`.
3. `php artisan config:clear` + `optimize:clear`. Sem restart de serviço (o artisan
   serve reavalia env/bootstrap por request); Evolution e webhook vivos INTOCADOS.

## Verificação (antes/depois)

- Request simulado do túnel DEPOIS: todas as URLs `https://painel.nextgest.com.br/...`;
  `Set-Cookie` com `secure; samesite=lax` (sessão e XSRF).
- Submit Livewire completo sob condições do túnel: **200 + "Credenciais invalidas"**
  (senha propositalmente errada) — o estágio sessão/CSRF passa; o que sobra é
  validação normal de credencial.
- Suíte completa: **530/530 verdes** (antes do hotfix: 530/530 — baseline registrado).
- Webhook externo: `wa.nextgest.com.br/webhook/evolution/{token-inválido}` → 401
  (inalterado); serviços ativos; mensagens seguem entrando (3.385).

## Risco residual / gate

- Nada de dados foi tocado (usuário/senha/migrations intactos — o gate de dados não
  foi necessário: o hash e o e-mail do dump estão íntegros).
- RateLimiter do login: 5 tentativas/60s por e-mail+IP. Com TrustProxies o IP agora é
  o real do visitante (antes, tudo chegava como 127.0.0.1 — o lockout de um IP
  travaria todo mundo).
- Se o Fabio ainda não conseguir logar após o fix, o próximo suspeito é senha
  (dado) — decisão dele (reset via `msg:auth:senha` só com ok explícito).
- `bootstrap/app.php` alterado está SEM commit (aguardando ok do Fabio).

## Pedido ao Fabio

Tentar logar em `https://painel.nextgest.com.br/login` (passando o OTP do Access) com
sistema5@engepecas.com.br + senha de sempre. Se falhar, anotar o horário exato e a
mensagem na tela (agora qualquer falha real aparece: "Credenciais invalidas" ou
"Muitas tentativas em Xs").
