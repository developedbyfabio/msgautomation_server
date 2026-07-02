# Prompt — msgautomation · Métricas · M-1 (painel /painel — números que já existem nos logs)
# SEQUÊNCIA NOTURNA 3/3 — pré-requisito: P-3 verde. Última fatia da noite.

> Você é o agente de execução (Claude Code). Trabalho com o Fabio (diretor/arquiteto). Comunicação
> direta, **pt-BR**, **sem emojis**. **Modo Hard noturno:** valem as REGRAS DA SEQUÊNCIA NOTURNA do
> arquivo da P-2 (1 commit por fatia, relatório em arquivo, parar se não voltar ao verde, nada real
> ligado/enviado).

## PRIMEIRO: pré-requisito e reorientação
1. Confirme na `docs/09` e no git que **P-2 e P-3 estão entregues**. Faltando, PARE e reporte.
2. `php artisan test` (sequencial) — anote o baseline verde. Vermelho = pare e reporte.

## Missão
Dar visão ao dono: `/painel` com os números que o sistema JÁ registra — sem coletar nada novo, sem
lib de gráfico, sem tocar no pipeline. Agregados SQL dos logs existentes, escopados por conta.
É a fatia N10 do desenho (doc 09).

## NÃO-NEGOCIÁVEL
- Nada destrutivo; migrations aditivas (idealmente NENHUMA — é leitura); git sem force; build
  foreground; testes sequenciais.
- **Sem lib nova.** Visual com o que já existe: cards Flux, tabelas, barras horizontais em
  CSS/Tailwind (largura proporcional). Nada de chart.js/apex/etc.
- **Leitura pura:** o painel não escreve nada de domínio (no máximo cache). Pipeline intocado.
- Tudo escopado; **TenantIsolationTest estendido** (painel da conta A só agrega dados da A).
- Os testes anteriores passam sem mudança de expectativa.

## Escopo do M-1
1. **Página `/painel`** (item no menu, primeiro da lista; padrão visual das telas):
   - **Seletor de período:** 7 dias / 30 dias / hoje (default 7), timezone São Paulo, aplicado a
     tudo.
   - **Cards de topo (números grandes):** mensagens recebidas (individuais; grupos contados à
     parte, discreto), respostas enviadas (total), % respondido automaticamente, tempo mediano de
     primeira resposta (incoming → primeiro envio pro mesmo contato no período; mediana, não média
     — outliers de horas distorcem).
   - **Respostas por origem (barras):** regra determinística / fluxo / IA (casou regra) / IA
     (base) / aprovação humana / manual / proativa — as origens que os logs de envio já carregam.
   - **IA (bloco):** decisões no período por ação (respondeu / escalou / silenciou), intents mais
     frequentes (top 5), consumo do dia x cota (contador Redis existente), pendências abertas
     agora (link pro /revisao).
   - **Fluxos (bloco):** sessões iniciadas, concluídas (chegaram a nó final), expiradas; fluxos
     mais usados (top 3).
   - **Kanban (bloco):** cards criados no período, transições por coluna destino (barras), cards
     por coluna AGORA (retrato).
   - **Proativas (bloco):** enviadas / puladas por motivo (barras) / falhadas no período; consumo
     do teto diário de hoje; campanhas ativas. Com tudo OFF os números são zero — o bloco mostra
     "nenhuma atividade proativa" honestamente.
2. **Implementação:** classe de leitura (`PainelMetrics`) com métodos por bloco, queries agregadas
   (GROUP BY) com índices existentes — confira EXPLAIN nas duas mais pesadas e anote no relatório;
   janela de datas sempre em SP convertida corretamente. **Cache leve por conta+período (60s)** pra
   navegação não martelar o banco. Polling da página mais espaçado (60s) ou sem polling (botão
   atualizar) — escolha e justifique no relatório.
3. **Vazio elegante:** período sem dados mostra zeros e traços, nunca erro/tela quebrada.

## Testes (dados semeados; nunca envio real)
- Semeie um cenário conhecido (X incoming, Y respostas por cada origem, decisões de IA, sessões de
  fluxo, transições de Kanban, targets proativos sent/skipped) e prove cada número/percentual/
  mediana exatos por período.
- Fronteira de período: evento fora da janela não conta; timezone SP na virada do dia.
- Mediana de primeira resposta: casos com resposta única, múltiplas (pega a primeira) e sem
  resposta (não entra na mediana).
- Cache: segunda leitura no mesmo período não repete as queries (assert de contagem de queries);
  expira/invalida ao trocar período.
- Sanidade de performance: renderização do painel com dados semeados executa nº de queries
  limitado e estável (assert de query count — sem N+1).
- Isolamento: gate estendido (dados semeados na conta B não alteram nenhum número da A).
- Regressão: suíte inteira verde, sequencial (baseline + novos).

## Encerramento — FIM DA SEQUÊNCIA NOTURNA
- `npm run build` foreground; reiniciar worker se necessário.
- Commit claro. Doc viva: `docs/09` (M-1 entregue; **escada de inteligência N0-N10 COMPLETA para
  uso pessoal**; próximos arcos: MT-1..3 quando a conta 2 for real — prompt futuro já preparado).
- **Relatório em `docs/relatorios/2026-07-02-m1.md`** + **RESUMO GERAL DA NOITE** no chat e em
  `docs/relatorios/2026-07-02-resumo-noite.md`: as 3 fatias (commits, números de testes inicial →
  final), o que o Fabio encontra pronto de manhã, o CHECKLIST DE MANHÃ consolidado (o da P-3 +
  abrir /painel e conferir os números contra a realidade + o teste real pendente do Kanban:
  mensagem da Claudia → card Novo → Em atendimento), e qualquer ressalva/decisão que ficou marcada
  "a confirmar".

### Resumo de uma linha
Construir a M-1: painel /painel escopado por conta com os números que os logs já têm — recebidas,
respostas por origem, mediana de primeira resposta, blocos de IA/fluxos/Kanban/proativas com barras
CSS e seletor de período em SP, leitura pura com cache de 60s e query count provado — fechando a
escada N0-N10 do uso pessoal e encerrando a noite com relatórios em arquivo e o checklist de manhã
consolidado pro Fabio.
