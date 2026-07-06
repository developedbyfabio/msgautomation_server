# Fatia 11 — Kanban "Aguardando Resposta": handoff + sem-resposta — 2026-07-06

Git no início: HEAD `e8f6b85` (fatia 10), working tree limpo exceto os dois relatórios untracked
pré-existentes (fora do commit, como sempre). Baseline: **757 verdes / 2966 assertions**.

---

## SURPRESA DO MAPEAMENTO (registrada e adaptada conscientemente)

**A coluna "Aguardando resposta" JÁ EXISTE desde a K-1.** As colunas reais do board default
(`BoardProvisioner::DEFAULT_COLUMNS`, design D4):

| pos | slug | nome |
|---|---|---|
| 0 | `novo` | Novo |
| 1 | `em_atendimento` | Em atendimento |
| 2 | **`aguardando`** | **Aguardando resposta** |
| 3 | `resolvido` | Resolvido |
| 4 | `reativacao` | Reativacao |

Ou seja: a coluna pedida já está **exatamente na posição do design travado** (logo após
`em_atendimento`), com slug `aguardando` — o prompt sugeria `aguardando_resposta`, mas mandava
"seguir o padrão das colunas existentes", e criar uma segunda coluna homônima seria absurdo.
**Adaptação:** usar o slug existente `aguardando`. O que faltava (e é o corpo da fatia) são as
**transições** até ela — nenhuma regra/ação apontava pra coluna; por isso o dono nunca via cards lá.

- **Provisioner:** já idempotente (`ensureDefaultBoard` = no-op se o board default existe; roda na
  migration K-1 e no hook `Account::created`). **Nenhuma mudança, nenhum backfill, zero migration.**
- **Confirmação por leitura em produção** (tinker, SELECT apenas): boards #1 (conta 1) e #2
  (conta 2) têm as 5 colunas D4 com `aguardando` na posição 2. Nada a inserir.
- **UI do board:** renderização 100% dinâmica (`@foreach ($columns ...)` no `kanban.blade.php`) —
  nada hardcoded, nenhuma mudança de apresentação.

## Onde `resposta_enviada` é emitido e como a corrida foi resolvida (destaque)

**Emissão:** `Sender::send()` (`app/Whatsapp/AutoReply/Sender.php:175-177`), após o envio efetivo
(`status='sent'`), dispara `AutoReplySent` → único listener `UpdateKanbanFromEvent` (ShouldQueue) →
`BoardEngine::apply('resposta_enviada', ...)` → BoardRule default #3 (`not_in_column
em_atendimento` → `em_atendimento`). O flag `handoff` da Fatia 5 **chega até o Sender** como
parâmetro (`dispatchFlowReply` → `SendAutoReply` → `Sender::send(handoff:)`), mas **não viajava no
evento** — essa era a corrida: handoff move o card pra `aguardando` no motor; o job de envio roda
depois; a despedida dispara `resposta_enviada`; a regra regride o card pra `em_atendimento`.

**Solução escolhida: SUPRESSÃO na emissão** (a primeira alternativa do prompt):
1. `AutoReplySent` ganhou `public readonly bool $handoff = false` (aditivo).
2. `Sender` repassa o flag que já possuía: `new AutoReplySent(..., handoff: $handoff)`.
3. `UpdateKanbanFromEvent`: `$event->handoff ? null : apply(...)` — a despedida de handoff não
   aplica a regra `resposta_enviada`.

**Justificativa contra a alternativa (move pós-envio):** o move no motor
(`FlowEngine::emitHandoff`) é determinístico e acontece **mesmo se o envio da despedida for
bloqueado/falhar** (kill switch, janela, teto, erro de provider) — mover só pós-envio deixaria a
pendência humana invisível exatamente nos casos em que o contato ficou mudo sem nem receber a
despedida. A supressão preserva o move determinístico como fonte única da posição do card e é
semanticamente honesta: a despedida é parte da ação de handoff, não uma "resposta que reabre
atendimento".

## Handoff → `aguardando`

`FlowEngine::emitHandoff`: destino do `moveToColumnSlug` trocado de `em_atendimento` para
`aguardando` (mesmo mecanismo da Fatia 5, `cause='handoff'`, idempotente por
`(card, 'handoff', session.id)`, erro isolado). `BoardEngine::moveToColumnSlug` ganhou o parâmetro
`string $cause = 'handoff'` (default preserva a assinatura da Fatia 5) para a causa própria do
unmatched.

## Unmatched → `aguardando` (toque cirúrgico no pipeline)

Ponto exato: `ProcessIncomingWhatsappMessage::avaliarAutoResposta`, ramo `$rule === null` →
`elseif ($guard->contactGatePasses(...))`, imediatamente após `UnmatchedMessage::record(...)`
(linha ~370). Diff no pipeline = **um bloco aditivo de 12 linhas**: `moveToColumnSlug('aguardando',
..., 'sem_resposta', message.id, cause: 'sem_resposta')` dentro de try/catch com log (padrão K —
falha do Kanban nunca derruba o pipeline). **Nenhuma decisão de resposta mudou**: o `record` e todos
os returns/branches ficaram byte a byte como estavam (diff completo no relatório de commit). Cobre
**ambos os modos**: pessoal (sem regra que case) e automático sem fluxo válido (fall-through da
degradação graciosa da Fatia 4 cai no mesmo ramo).

**Observação registrada (deliberada):** existe um segundo call site de `UnmatchedMessage::record`
em `ClassifyWithAi::registrarSemResposta` (IA terminou em silêncio; IA é OFF por padrão). Os
limites da fatia autorizam tocar **apenas** `ProcessIncomingWhatsappMessage`; o caso da IA fica
consistente de implementar numa fatia futura (mesmo padrão best-effort) — anotado, não improvisado.

## Ajustes deliberados em testes (um a um)

**Fatia 5 (mudança de destino do handoff, esperada pelo prompt):**
1. `FlowHandoffTest::test_handoff_executa_os_quatro_efeitos` — assert do card:
   `em_atendimento` → `aguardando` (novo destino); nota da corrida atualizada.
2. `FlowHandoffTest::test_handoff_move_o_card_deterministicamente_sem_depender_de_regras` — idem
   (só o slug esperado).
3. `FlowTemplateTest::test_handoff_do_fluxo_instanciado_executa_os_efeitos` (Fatia 7, herda o
   comportamento da 5) — idem.

**K-1/K-2 (consequência direta do move determinístico de unmatched — os cenários usavam mensagem
sem match como setup; o intento de cada teste foi preservado e o novo estado final documentado):**
4. `KanbanEngineTest::test_primeira_mensagem_cria_card_em_novo_com_transicao` — a criação em Novo
   pela regra segue provada na **1ª transição** (asserts de causa intactos); estado final agora é
   `aguardando` com a 2ª transição `sem_resposta`.
5. `KanbanEngineTest::test_mensagem_em_card_resolvido_reabre_pra_novo` — a reabertura
   (resolvido→novo, causa regra) segue asserta; o final é `aguardando` (mensagem reaberta sem
   resposta = pendência humana).
6. `KanbanEngineTest::test_reentrega_do_mesmo_evento_nao_duplica_card_nem_transicao` — o
   processamento normal agora gera 2 transições (regra + sem_resposta); o assert virou breakdown
   por tipo (1 + 1 = 2) provando que a re-entrega não duplica **nenhuma**.
7. `KanbanEngineTest::test_first_match_respeita_ordem_e_regra_inativa_e_ignorada` — first-match e
   regra inativa agora provados pela transição de criação em Novo; final `aguardando`.
8. `KanbanUiTest::test_mover_manual_registra_transicao_manual` — o card parte de `aguardando`
   (não mais `novo`); o move manual do teste vai pra `resolvido` (coluna diferente) pra continuar
   provando a causa `manual`.
9. `KanbanUiTest::test_mover_pra_mesma_coluna_e_noop` — "mesma coluna" agora é a coluna atual do
   card (`aguardando`), preservando o intento (no-op sem transição).
10. `KanbanUiTest::test_renomear_preserva_slug_e_regras_seguem_movendo` — regra pós-renomeação
    provada pela transição pra coluna renomeada; final `aguardando`.
11. `KanbanUiTest::test_desativar_regra_default_para_o_movimento_com_confirmacao` — regra OFF
    provada por **zero transições causa 'regra'**; o card em `aguardando` via `sem_resposta` é
    ação de SISTEMA (mesma doutrina do handoff: determinístico, não desligável por BoardRule).
12. `TagsTest::test_remove_tag_no_motor_e_reentrega_idempotente` — contagem de transições virou
    breakdown (1 `mensagem_recebida` + 1 `sem_resposta` = 2), re-entrega sem duplicar.

## Arquivos de teste e cobertura

- `tests/Feature/KanbanAguardandoTest.php` (**novo**, 8 casos):
  - board default nasce com `aguardando` logo após `em_atendimento` + `ensureDefaultBoard`
    idempotente (2ª chamada não duplica board/colunas);
  - **a prova da corrida**: handoff completo com fila processada (menu → card `em_atendimento` pela
    regra [regressão de passagem]; opção 1 → despedida `sent` E card **termina** em `aguardando`;
    última transição = handoff→aguardando; zero transição `resposta_enviada` posterior regredindo);
  - resposta normal de regra segue movendo pra `em_atendimento`;
  - unmatched modo pessoal → card `aguardando` (causa `sem_resposta`), decisão de resposta idêntica
    (0 auto_reply_logs, 1 UnmatchedMessage);
  - unmatched modo auto sem fluxo válido → idem;
  - Kanban inteiro quebrado (mock lançando exceção) → pipeline segue (mensagem persistida +
    unmatched registrado);
  - isolamento A/B: unmatched em A não toca o board de B (mesmo JID);
  - idempotência: mesmo `(event_type, event_ref)` duas vezes = 1 transição.
- Ajustados: listados acima (12).

## Contagem de testes

| | testes | assertions |
|---|---|---|
| Antes | 757 | 2966 |
| Depois | **765** | **3001** |

Suíte inteira **sequencial**, tudo verde — zero regressão fora dos ajustes deliberados listados.

## Confirmações explícitas

- Coluna existente confirmada por leitura nos boards de produção (contas 1 e 2); nada inserido,
  nada removido/renomeado; **zero migration**.
- Diff de produção: 6 arquivos de app (`AutoReplySent`, `Sender`, `UpdateKanbanFromEvent`,
  `BoardEngine`, `FlowEngine`, `ProcessIncomingWhatsappMessage`) — o pipeline só ganhou o bloco
  best-effort autorizado; transições novas são determinísticas (padrão Fatia 5), **não**
  BoardRules customizáveis.
- Erro de Kanban isolado nos três pontos (listener, handoff, unmatched); isolamento por conta
  preservado (`runAs` por `account_id` explícito).

## Commit
Local, sem push (Fabio empurra). Hash reportado na resposta.
