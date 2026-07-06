# Fatia 21 — Tela de login: fundo, centralização e responsividade — 2026-07-06

**Nota de ordem:** esta fatia chegou DEPOIS das 22 e 23 (prompt escrito antes delas). O baseline
do prompt (871/`35b90b2`) foi substituído pelo **real no início: 890 verdes / 3553 assertions
(HEAD `42073e2`)** — registrado e usado. O `public/fundo.webp` untracked observado nas fatias
22/23 era o asset desta fatia (agora referenciado e commitado junto, para o repo ficar
auto-contido; a imagem não foi gerada/alterada — é a que o dono colocou no servidor).

---

## Mapeamento (lido antes de escrever)

- **Login tem layout guest PRÓPRIO**: `components/layouts/auth.blade.php`
  (`#[Layout('components.layouts.auth')]` no Livewire Login) — a mudança fica **isolada do painel
  autenticado** (`app.blade.php`) por construção.
- **O desafio 2FA (`auth/two-factor-challenge.blade.php`) COMPARTILHA a mesma casca**
  (`<x-layouts.auth>`) → ganha o mesmo tratamento coerentemente (permitido pela fatia). Nenhuma
  outra tela usa o layout.
- **Causa confirmada da barra de rolagem:** `min-h-screen` (= `min-height: 100vh`) no body do
  layout — no iOS Safari o 100vh conta a área sob a barra de endereço.
- Asset: `public/fundo.webp` presente (917 KB), referenciado via `asset('fundo.webp')`.

## Implementação (apresentação apenas — 3 blades)

- **`layouts/auth.blade.php`:** container full-screen com
  `style="min-height: 100vh; min-height: 100dvh"` (o segundo sobrescreve onde suportado —
  navegador antigo cai no 100vh), `flex items-center justify-center` (centralização real nos dois
  eixos), `background-image: fundo.webp` + `bg-cover bg-center bg-no-repeat`, **fallback de cor
  sólida** = o `bg-zinc-100 dark:bg-zinc-950` do body (aparece se a imagem não carregar),
  **overlay de legibilidade** `bg-zinc-950/45 + backdrop-blur-[2px]` entre a imagem e o card
  (funciona nos dois temas). Conteúdo com `py-8`: em telas muito baixas a página cresce de forma
  controlada (nunca overflow acidental); no layout padrão, zero scroll.
- **`login.blade.php` / `two-factor-challenge.blade.php`:** só o CONTRASTE dos textos que ficam
  FORA do card (logo/h1/subtítulo/link "Voltar pro login") — claros com drop-shadow sobre o
  overlay escuro, nos dois temas. O card em si (bg sólido branco/zinc-900) ficou como era —
  visual atual mantido, legível sobre qualquer imagem. Card `max-w-sm` + `px-6` (fluido no mobile,
  nunca colado nas bordas; confortável no desktop) — **já era assim**, mantido.

## Para o dono validar no device

1. Abrir `painel.nextgest.com.br/login` no celular: **sem nenhuma barra de rolagem**, card no
   centro exato da tela (mesmo com a barra de endereço do Safari/Chrome aparecendo/sumindo).
2. Girar o aparelho / testar em tela pequena (~360px): card fluido com respiro lateral.
3. Desktop: fundo cobre a tela toda, card centralizado, textos legíveis (overlay escuro).
4. Tema claro e escuro: overlay e contraste valem nos dois.
5. O desafio 2FA (quando ativado) tem a mesma cara.

## Testes

- **`LoginViewTest` (novo, 2):** smoke da estrutura (form E-mail/Senha/Manter conectado/Entrar),
  `fundo.webp` referenciado no HTML, o par `100vh; 100dvh` presente, fallback de cor no body;
  desafio 2FA sem sessão pendente segue redirecionando pro login (comportamento existente).
- **Contrato de auth SEM alteração:** `AuthTest`, `LoginHardeningTest` (rate limiting),
  `PerfilE2faTest`, `AdminDoisFatoresTest` — 30 testes verdes intocados. Nenhum teste existente
  ajustado.

## Contagem de testes

| | testes | assertions |
|---|---|---|
| Antes (real, pós-23) | 890 | 3553 |
| Depois | **892** | **3564** |

Suíte inteira **sequencial**, tudo verde.

## Confirmações explícitas

- **Nenhuma mudança em lógica de login/auth/rate-limiting/2FA** (diff = 3 blades + teste + asset;
  zero PHP de aplicação); painel autenticado intocado (layout separado). Zero migration.
- Assets rebuildados em foreground (`backdrop-blur` confirmado no bundle).
- `queue:restart` **não necessário e não executado**: nenhum código carregado por job mudou
  (só views guest + teste) — critério da fatia atendido e registrado.

## Commit
Local, sem push (Fabio empurra). Hash reportado na resposta.
