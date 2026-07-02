# Prompt — msgautomation · Proativas · P-3 (disparo real: tick + claim atômico + Sender proactive — TUDO OFF)
# SEQUÊNCIA NOTURNA 2/3 — pré-requisito: P-2 verde. Ao terminar VERDE, siga para docs/msgautomation-m1-metricas.md

> Você é o agente de execução (Claude Code). Trabalho com o Fabio (diretor/arquiteto). Comunicação
> direta, **pt-BR**, **sem emojis**. **Modo Hard noturno:** o Fabio NÃO está acompanhando. Valem as
> REGRAS DA SEQUÊNCIA NOTURNA do arquivo da P-2 (ordem, 1 commit por fatia, relatório em arquivo,
> parar a sequência se não voltar ao verde, e NUNCA ligar/aprovar/opt-in/enviar nada real).

## PRIMEIRO: pré-requisito e reorientação
1. Confirme na `docs/09` e no git que **P-1 e P-2 estão entregues** (guard/consents e campanhas com
   snapshot/agenda). Faltando qualquer uma, PARE a sequência e reporte.
2. `php artisan test` (sequencial) — anote o baseline verde. Vermelho = pare e reporte.

## Missão e regra de ouro
Esta é a fatia que constrói o DISPARO das proativas — o código mais perigoso do sistema. Por isso
ele nasce **inteiro dentro da jaula da P-1**: nenhum caminho de envio existe sem passar pelo
`ProactiveGuard` (check + claim atômico) e pelo Sender em modo `proactive` com re-check R2 no
instante do POST. E nesta noite **nada liga**: `proactive_enabled` segue OFF, zero campanhas
aprovadas reais, zero opt-ins reais — o primeiro disparo de verdade é gate do Fabio, de dia, com a
campanha de teste dele.

## NÃO-NEGOCIÁVEL
- Nada destrutivo; migrations aditivas; git sem force; build foreground; testes sequenciais.
- Tudo escopado; **TenantIsolationTest estendido** (tick/dispatch de uma conta jamais toca outra).
- **Todo envio proativo passa por:** guard.check (todos os 9 freios) → claim atômico dos
  contadores (diário conta + semanal contato) → Sender modo `proactive` → **R2 próprio**
  (re-executa o guard imediatamente antes do POST; kill switch/opt-out/janela mudaram no meio =
  aborta e devolve o claim se o desenho dos contadores permitir, senão registra o consumo com
  motivo). `{senha:}` bloqueado (herda da P-1).
- Idempotência dura por target: UNIQUE/claim garante que re-execução de job/tick NUNCA duplica
  envio (nem com worker duplo).
- Proativa NUNCA responde mensagem — só inicia. O pipeline reativo segue intocado.

## Escopo do P-3
1. **Tick (`proactive:tick`, schedule a cada minuto):** por conta COM `proactive_enabled` (hoje:
   nenhuma — o tick roda e não faz nada, barato), busca targets `pending` com `scheduled_at <= now`
   de campanhas `approved`, e despacha `SendProactiveMessage` um a um (fila). O tick NÃO envia —
   só enfileira. Lote pequeno por rodada (config, default 5) pra nunca rajar.
2. **Job `SendProactiveMessage`:** claim do target (status pending → processing atômico; corrida
   perde e sai) → guard.check completo → claim dos tetos → render dos placeholders comuns →
   Sender modo `proactive` com R2 → resultado:
   - **sent:** marca target sent + `sent_at` + log (reusar/estender o log de envio existente com
     origem `proactive` + campaign_id).
   - **bloqueado pelo guard:** teto diário da conta → target VOLTA a pending com `scheduled_at`
     empurrado pro próximo dia dentro da janela (a campanha continua amanhã, sem perder ninguém);
     opt-out/off/sem opt-in → **skipped** com motivo (nunca mais tenta esse contato); janela
     fechada → reagenda pra abertura da janela; kill switch OFF → target volta a pending SEM novo
     scheduled_at (aguarda religada) e o job para de processar a campanha nesta rodada.
   - **erro transitório do HTTP:** retry limitado (max 2, backoff) via mecanismo padrão da fila;
     esgotou → failed com motivo. Retry JAMAIS duplica (idempotência por claim).
3. **Estados de campanha completados:** approved → **running** (primeiro target processado) →
   **done** (nenhum pending/processing restante) | **paused** (botão) | **cancelled**. Pausar:
   targets pending param de ser tickados; retomar recalcula `scheduled_at` dos vencidos (janela +
   jitter). Cancelar: pendings viram skipped/cancelada. Des-aprovar bloqueado se existir sent.
4. **Opt-out no meio da campanha:** revogação (palavra ou manual, da P-1) faz os targets pending
   daquele contato em QUALQUER campanha virarem skipped/opt-out — teste explícito.
5. **UI (estender `/campanhas`):** barra de progresso real (sent/skipped/failed/pending com
   contadores), lista de targets com status+motivo+hora, botões Pausar/Retomar/Cancelar (modais),
   badge de estado. Card da campanha mostra "aguardando interruptor de proativas" quando
   `proactive_enabled` está OFF e há approved — honestidade na tela.
6. **Observabilidade:** cada decisão do job logada (nível info/warning) com campaign/target/motivo;
   contadores do dia visíveis no card Proativas de `/configuracoes` (consumo x teto).

## Testes (mock TOTAL do HTTP — `Http::assertNothingSent()` fora dos mocks; nunca envio real)
- Tick: só contas com switch ON; só campanhas approved; lote respeitado; conta OFF = zero jobs.
- Caminho feliz: target vence → guard ok → claim → POST mockado → sent + log origem proactive.
- Corrida: dois jobs no mesmo target → um envia, outro sai (claim atômico) — zero duplicata.
- Teto diário: estoura no meio → restantes reagendados pro dia seguinte na janela; contador por
  conta correto; semanal por contato idem.
- R2: opt-out entre o check e o POST → aborta, skipped/opt-out, nada enviado; kill switch desligado
  no meio → pending aguardando, nada enviado.
- Janela: fora → reagenda pra abertura; jitter na faixa.
- Retry: erro 5xx mockado → retenta e envia UMA vez; esgotado → failed com motivo.
- Pausar/retomar/cancelar; done quando esvazia; des-aprovar bloqueado com sent.
- Opt-out global (mode off) e revogação no meio pulam o contato em todas as campanhas.
- Isolamento: gate estendido (tick da conta A não enfileira B; contadores separados).
- Regressão: suíte inteira verde, sequencial (baseline + novos), sem mudança de expectativa.

## Encerramento
- `npm run build` foreground; **reiniciar worker** (jobs novos); conferir schedule registrado.
- Commit claro. Doc viva: `docs/09` (P-3 entregue — arco proativo COMPLETO, aguardando gate do
  Fabio pro primeiro disparo real).
- **Relatório em `docs/relatorios/2026-07-02-p3.md`** + resumo no chat, incluindo o **CHECKLIST DE
  MANHÃ do Fabio** (passo a passo do primeiro teste real controlado): 1) opt-in em 1 contato de
  teste seu; 2) criar campanha "teste" com público = esse contato, mensagem inócua; 3) preview →
  aprovar; 4) ligar `proactive_enabled` em /configuracoes (modal); 5) observar o envio no minuto
  da janela/jitter + card em /campanhas + log; 6) mandar a palavra PARAR do contato e ver a
  revogação; 7) DESLIGAR o interruptor ao fim do teste.
- **Se e somente se verde e commitado:** abra e execute `docs/msgautomation-m1-metricas.md`.

### Resumo de uma linha
Construir a P-3: o disparo real das proativas inteiro dentro da jaula — tick por minuto que só
enfileira, job com claim atômico por target e pelos tetos, Sender em modo proactive com R2 no
instante do POST, teto diário que pausa e retoma amanhã, opt-out no meio pulando o contato em tudo,
retry idempotente, pausar/retomar/cancelar com progresso na tela — tudo construído e provado com
mock, nada ligado, e o primeiro envio de verdade esperando o checklist de manhã do Fabio.
