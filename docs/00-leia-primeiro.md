# LEIA PRIMEIRO — Fila de execução noturna (msgautomation)

Fabio deixou uma fila de prompts pra você (agente Claude Code no VPS) executar **em ordem**,
um por vez, durante a noite. Este arquivo manda como conduzir. Leia inteiro antes de começar.

## Regra mestra: sequencial e seguro, NÃO "engolir tudo"
Você executa os prompts **na ordem numérica**, um de cada vez. Entre um e o próximo:

1. Rode a suíte completa: `php artisan test`.
2. Se **tudo verde** → commita aquele prompt (git sem force), escreve um relatório curto em
   `docs/relatorios/` e passa pro próximo.
3. Se **qualquer teste quebrar, ou você ficar em dúvida sobre escopo/ambiente** → **PARE.**
   Não tenta "dar um jeito", não segue pro próximo, não faz commit quebrado. Deixa a árvore no
   último estado verde (usa git pra reverter só o que você mexeu neste prompt se precisar) e
   escreve no relatório exatamente onde parou e por quê. O Fabio resolve de manhã.

O objetivo do Fabio é **não quebrar nada**. Um prompt a menos feito é melhor que um erro
empilhado. Prefira parar a improvisar.

## Limites duros (valem pra TODOS os prompts, inegociável)
- Nada destrutivo: sem DROP/TRUNCATE/migrate:fresh/migrate:reset. UPDATE/DELETE só com WHERE.
- Migrations **aditivas** apenas (nunca remover/renomear coluna existente de forma destrutiva).
- git **sem force**. Build/serve em **foreground**. Testes **sequenciais** (`php artisan test`).
- Segredos só no `.env`/cofre — nunca em código, nunca em log.
- **Não** tocar no canal Evolution nem no fluxo reativo que está em produção.
- `TenantIsolationTest` continua **gate**: toda mudança tem que passar por ele.
- Antes de mexer, leia `docs/09` e `docs/10` pra contexto.
- Cada prompt roda a suíte como **baseline** antes de começar (registra o nº de verdes) e de novo no fim.

## Contexto do ambiente (aprendido hoje, pra você não tropeçar)
- Laravel 13 + Livewire 4 + Flux (free) + Alpine + Tailwind v4, MySQL 8, Redis (predis, 6380), PHP 8.5.
- Produção no VPS atrás de Cloudflare Tunnel + Cloudflare Access. `trustProxies` já configurado.
- **Servidor em UTC** — na hora de logar/exibir horário, atenção ao fuso (SP = UTC-3). Não confie
  em "hora do servidor" como hora local.
- Dois canais vivos: `fabio-pessoal` (Evolution) e o canal Cloud (Meta, hoje em número de teste).
- Auth hoje é só email+senha. **O 2FA (prompt 01) é prioridade** porque há regra com dados
  bancários reais do Fabio em texto (`/regras`), e o painel precisa de proteção mais forte.

## Ordem da fila
1. `01-2fa-e-perfil.md` — 2FA (TOTP via Fortify) + página de Perfil (trocar email/senha). **Segurança, faz primeiro.**
2. `02-pagina-de-logs.md` — aba de Logs/Eventos no painel (o que hoje a gente pede pro agente "escutar").
3. `03-conversas-input.md` — Enter envia / Ctrl+Enter quebra linha / textarea que cresce / emojis.
4. `04-anexos-parte-a-imagens.md` — enviar imagens (foto) pelas conversas.
5. `05-anexos-parte-b-pdf.md` — enviar PDF/documentos.
6. `06-anexos-parte-c-audio.md` — enviar áudio (base pro áudio-robô futuro).

Comece pelo `01`. Boa noite de trabalho — com calma, verde a verde.
