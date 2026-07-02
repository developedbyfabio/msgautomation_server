# RESUMO DA NOITE — 2026-07-02 (P-2 -> P-3 -> M-1)

Sequencia noturna completa, as 3 fatias VERDES e commitadas, 1 commit por fatia.
Suite: **402 -> 442 verdes** (sequencial, sem mudanca de expectativa nos anteriores).
**NADA real foi ligado, aprovado, opt-in ou enviado.** O robo reativo em producao
seguiu intocado a noite toda (worker reiniciado limpo apos cada fatia; /up 200).

## As 3 fatias

| Fatia | Commit | Suite | Relatorio |
|---|---|---|---|
| P-2 Campanhas (draft->preview->aprovar + agenda com jitter; SEM disparo) | `e1c765f` | 402 -> 416 | docs/relatorios/2026-07-02-p2.md |
| P-3 Disparo real (tick + claim atomico + Sender proactive + R2; TUDO OFF) | `cf361c7` | 416 -> 432 | docs/relatorios/2026-07-02-p3.md |
| M-1 Painel /painel (metricas dos logs; leitura pura + cache 60s) | (este) | 432 -> 442 | docs/relatorios/2026-07-02-m1.md |

## O que voce encontra pronto de manha
- **/campanhas**: criar campanha -> preview da lista EXATA (excluidos com motivo) ->
  aprovar (modal forte congela snapshot + agenda com jitter na janela) -> progresso
  real, destinatarios, pausar/retomar/cancelar. Badge "aguardando interruptor".
- **Disparo blindado** (dormindo): tick por minuto que so enfileira; job com claim
  atomico do target e dos tetos, 9 freios + R2 no instante do POST, teto do dia
  empurra pra amanha, opt-out no meio pula o contato em TUDO, retry idempotente.
- **/painel** (1o item do menu): recebidas, respostas por origem, % automatico,
  mediana de 1a resposta, blocos IA/fluxos/Kanban/proativas com barras, periodos
  hoje/7d/30d em SP, botao Atualizar.

## CHECKLIST DE MANHA (consolidado)
1. **Scheduler (1 passo de infra):** o schedule esta registrado mas nao ha processo
   rodando. Instalar o unit de referencia:
   `sudo cp deploy/systemd/msgautomation-scheduler.service /etc/systemd/system/ && sudo systemctl daemon-reload && sudo systemctl enable --now msgautomation-scheduler`
2. **Primeiro disparo proativo controlado** (roteiro completo no relatorio da P-3):
   opt-in em 1 contato de teste SEU -> campanha "teste" com publico = ele -> preview
   -> aprovar -> LIGAR o interruptor em /configuracoes -> observar envio/progresso/
   log -> mandar PARAR do contato e ver a revogacao -> DESLIGAR o interruptor.
3. **/painel**: abrir e conferir os numeros contra a realidade (recebidas do dia,
   respostas por origem, pendencias da IA).
4. **Teste real pendente do Kanban:** mensagem da Claudia -> card nasce em "Novo" ->
   apos a resposta, move pra "Em atendimento" (conferir em /kanban).

## Ressalvas / "a confirmar" registradas
- **Agenda proativa e "dia util":** o transbordo vai pro PROXIMO dia (a janela nao
  distingue fim de semana nesta fase). Se quiser pular sabado/domingo, e um ajuste
  pequeno no AgendaBuilder (P-4).
- **Teto semanal no disparo:** target bloqueado pelo limite semanal do contato e
  reagendado +7 dias (interpretacao minha — o desenho so especificava o teto diario).
- **% automatico do painel:** definido como mode 'auto' sobre o total enviado
  (tooltip explica). Mediana de 1a resposta: por CONTATO no periodo (1o incoming ->
  1a resposta). Ajustaveis se preferir outra definicao.
- **EXPLAIN do painel:** indices atuais bastam pro volume pessoal; indice composto
  (account_id, sent_at) fica anotado como upgrade futuro.
- **P-4 (futura):** reativacao por tempo via Kanban (TempoEstourou) — o desenho da
  fatia 9 original; o disparo entregue nesta noite ja cobre follow-up/lembrete via
  campanhas.

## Roadmap
Escada N0-N10 **COMPLETA pra uso pessoal**. Proximos arcos (doc 09): MT-1..MT-3
(multi-usuario, canal por conta, onboarding) quando a conta 2 for real.
