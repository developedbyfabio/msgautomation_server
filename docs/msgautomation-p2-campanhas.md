# Prompt — msgautomation · Proativas · P-2 (campanhas: draft → preview → aprovar + agenda com jitter — SEM disparo)
# SEQUÊNCIA NOTURNA 1/3 — ao terminar VERDE, siga para docs/msgautomation-p3-disparo.md

> Você é o agente de execução (Claude Code). Trabalho com o Fabio (diretor/arquiteto). Comunicação
> direta, **pt-BR**, **sem emojis**. **Modo Hard noturno:** o Fabio NÃO está acompanhando. Rode até
> o fim sem acordá-lo; pare a fatia (e a SEQUÊNCIA) apenas se algo destrutivo/irreversível for a
> única saída ou se a suíte não voltar ao verde.

## REGRAS DA SEQUÊNCIA NOTURNA (valem pras 3 fatias)
- Ordem: P-2 → P-3 → M-1. **Uma fatia só começa se a anterior terminou verde e commitada.**
  Falhou e não conseguiu consertar? Escreva o relatório de falha e PARE a sequência inteira.
- 1 fatia = 1 commit próprio (não misturar).
- **Relatório em arquivo** (o Fabio lê de manhã): `docs/relatorios/2026-07-02-p2.md` (data real),
  com o mesmo conteúdo do relatório final de sempre. Além do resumo no chat.
- NUNCA, em nenhuma fatia: ligar `proactive_enabled`, aprovar campanha real, dar opt-in em contato
  real, enviar qualquer mensagem real. Tudo via teste com mock (`Http::assertNothingSent()` nos
  caminhos novos). O robô reativo em produção segue intocado.

## PRIMEIRO: pré-requisito e reorientação
1. Leia `docs/09` — N8 e D5. Confirme que **P-1 está registrada como entregue** (freios,
   ProactiveGuard, opt-in/consents). Se P-1 não estiver entregue, PARE e reporte (não improvise).
2. `git log --oneline -10` e `php artisan test` (sequencial) — anote o número verde como baseline.
   Vermelho = pare e reporte.

## Missão
Campanha proativa como objeto de primeira classe com **gate humano estrutural**: rascunho → preview
da lista EXATA → aprovação do dono → agenda materializada com jitter. **Nada dispara nesta fatia**
(o job de envio é a P-3). Ao final da noite, o Fabio encontra a tela pronta pra criar a primeira
campanha de teste dele — desligada.

## NÃO-NEGOCIÁVEL
- Nada destrutivo; migrations aditivas; git sem force; build foreground; testes sequenciais.
- Tudo escopado; **TenantIsolationTest estendido**.
- **Público só com opt-in:** a resolução de público NUNCA inclui contato sem `proactive_opt_in`,
  com `auto_reply_mode=off` ou grupo — filtro estrutural, não de UI.
- `{senha:}`/segredo proibido na mensagem da campanha (validação no save, coerente com o guard).
- Aprovar TRAVA mensagem e público (snapshot) — editar depois exige voltar pra draft (des-aprovar).

## Escopo do P-2
1. **Schema (aditivo, escopado):**
   - `proactive_campaigns`: account_id, name, message (template; placeholders comuns ok, segredo
     não), audience_type (tags / coluna_kanban / contatos) + audience_config (JSON: tag_ids,
     column_id, contact_ids), status (draft / previewed / approved / cancelled — running/done são
     da P-3), start_at (nullable = "assim que aprovada e dentro da janela"), approved_at/by,
     timestamps.
   - `campaign_targets`: campaign_id, contact_id, status (pending / skipped — sent/failed são P-3),
     skip_reason nullable, scheduled_at nullable, UNIQUE (campaign, contact), timestamps.
2. **Resolução de público (`AudienceResolver`):** dado o audience_config, resolve a lista de
   contatos elegíveis AGORA: aplica o filtro estrutural (opt-in obrigatório, mode!=off, sem grupo)
   e registra os EXCLUÍDOS com motivo (sem opt-in / off / grupo) pro preview mostrar. Por tags =
   qualquer uma das tags; por coluna = cards atuais na coluna; por contatos = lista explícita.
3. **Fluxo de estados:**
   - **Draft:** edita tudo.
   - **Preview:** resolve o público e MOSTRA a lista exata (nomes/números) + contagem + excluídos
     com motivo + a mensagem renderizada de exemplo (placeholders com dados do primeiro contato).
     Público muda no mundo? Preview re-resolve ao abrir (é retrato, não trava ainda).
   - **Aprovar (modal de confirmação forte, padrão kill switch):** congela snapshot — cria os
     `campaign_targets` (pending) da lista previewed e trava mensagem/público. Registra
     approved_at/by.
   - **Cancelar:** de qualquer estado; targets pendentes viram skipped/cancelada.
   - Des-aprovar (voltar a draft) só se NENHUM target foi enviado (na P-3 isso importa; aqui é
     sempre possível) — apaga targets pendentes.
4. **Materialização da agenda (sem disparar):** ao aprovar, cada target ganha `scheduled_at`:
   distribuídos a partir de `start_at` (ou de agora), SEMPRE dentro da janela proativa (P-1),
   espaçados com jitter aleatório entre `proactive_jitter_min` e `_max`; o que não cabe na janela
   de hoje transborda pro próximo dia útil da janela. Determinístico o suficiente pra teste
   (seed/injeção de relógio). O tick/consumo é a P-3 — aqui só o cálculo e a gravação.
5. **UI (`/campanhas`, item no menu; padrão das telas):** lista (nome, status com badge, público
   resumido, progresso placeholder), criar/editar draft (form com tipo de público e selects
   reusando os seletores existentes de tags/colunas/contatos), tela/modal de preview (lista exata,
   excluídos com motivo, mensagem de exemplo), botões Aprovar (modal forte)/Cancelar. Tooltip
   honesto no topo: "campanhas só disparam com o interruptor de proativas ligado, dentro da janela
   e dos tetos — e o disparo chega na próxima fatia".

## Testes (mock — nunca envio real)
- Resolução de público: cada tipo (tags/coluna/contatos); filtro estrutural exclui sem opt-in, off
  e grupo COM motivo; contagens batem.
- Estados: draft→preview→approved cria targets do snapshot; editar approved bloqueado; des-aprovar
  apaga pendentes e libera edição; cancelar marca skipped.
- Agenda: scheduled_at dentro da janela, jitter dentro da faixa, transbordo pro dia seguinte,
  UNIQUE por contato.
- Segredo na mensagem = bloqueado no save. `Http::assertNothingSent()` em todos os fluxos.
- Isolamento: campanhas/targets/resolução por conta (gate estendido; tags/colunas homônimas não
  cruzam).
- Regressão: suíte inteira verde, sequencial (baseline + novos), sem mudança de expectativa.

## Encerramento
- `npm run build` foreground; reiniciar worker se fila mudou.
- Commit claro. Doc viva: `docs/09` (P-2 entregue).
- **Relatório em `docs/relatorios/2026-07-02-p2.md`** + resumo no chat: o que entrou, schema,
  fluxo de estados, regras da agenda, números dos testes, confirmação de zero disparo e de que
  tudo segue OFF.
- **Se e somente se verde e commitado:** abra e execute `docs/msgautomation-p3-disparo.md`.

### Resumo de uma linha
Construir a P-2: campanhas proativas com público resolvido só entre opt-ins (excluídos listados com
motivo), fluxo draft → preview da lista exata → aprovação com modal forte que congela snapshot e
materializa a agenda com jitter dentro da janela — sem nenhum disparo (P-3), sem segredo na mensagem,
tudo OFF, escopado, testado com mock, relatório em arquivo e sequência seguindo só se verde.
