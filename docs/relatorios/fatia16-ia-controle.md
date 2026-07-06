# Fatia 16 — IA: diagnóstico do controle, consolidação global e follow-up do Kanban — 2026-07-06

Git no início: HEAD `ce3ff4e` (fatia 15), working tree limpo exceto os dois relatórios untracked
pré-existentes (fora do commit). Baseline: **813 verdes / 3260 assertions**.

---

## PARTE A — Diagnóstico (feito antes de qualquer código)

**1. Composição exata do `aiEligible`** (`AntiBanGuard`, pré-fatia):

```php
public function aiEligible(int $accountId, string $jid): bool
{
    $settings = $this->settingsFor($accountId);
    if (! $settings->ai_enabled)              return false; // GLOBAL (kill switch por conta)
    if ($this->isGroup($jid))                 return false; // grupo fora
    if (! $this->aiContactEnabled(...))       return false; // <-- FLAG POR CONTATO (Contact.ai_enabled)
    return $this->contactGate(...)->allowed;               // allowlist/mute
}
```

**2. O que o dono viu na aba Contatos:** dois elementos de IA POR CONTATO — (a) badge "IA"
(sparkles, indigo) na listagem quando `ai_enabled && ai_mode !== 'rules_only'`; (b) no painel de
edição do contato, a seção **"IA para este contato"** (checkbox + select de modo
intencao/aprovacao/conhecimento/rules_only). Além deles, o "Silenciar (off)" — que é o **mute do
robô** (regras+fluxos+IA), não um controle de IA.

**3. Campo por contato existe?** SIM: `Contact.ai_enabled` (boolean, default false) e
`Contact.ai_mode` (default `'intencao'`; o job usa `$contact?->ai_mode ?: 'intencao'`).
**Impacto medido em produção (SELECT de leitura): ZERO** — 57 contatos no total, **0** com
`ai_enabled=true`, **0** com `ai_mode != 'intencao'`. A consolidação não muda comportamento para
nenhum contato real.

**4. Global em Configurações:** seção "IA classificadora" (card indigo com switch, info-tip,
"Estado: ON/OFF (desligada)", limiar de confiança e temas de aprovação). Visível e funcional; a
única frase desatualizada pela consolidação era "Precisa do robo ligado **e do contato com IA
ligada**".

**5. Desfechos do `ClassifyWithAi`:** (a) casou regra acima do limiar → responde com a resposta DA
regra; (b) abaixo do limiar/tema sensível → **escala pra fila `/revisao`** (pendência humana);
(c) modo conhecimento → responde fundamentado na base ou escala/silencia; (d) **sem candidatas/sem
fundamento/vários caminhos → `registrarSemResposta`** (helper único chamado de 7 pontos —
`UnmatchedMessage::record`), que era o follow-up da Fatia 11.

**CONCLUSÃO: CENÁRIO 2** (existe controle de IA por contato) — consolidar no global.

## PARTE B — Entrega (cenário 2)

- **`aiEligible` global-only:** a checagem `aiContactEnabled` saiu da composição (ponto cirúrgico
  autorizado i); o método morto foi removido (nenhum outro caller em app/testes). Grupo, gate e
  mute continuam exatamente como estavam — **contato silenciado segue vetado**.
- **UI por contato saiu:** badge "IA" da listagem e a seção "IA para este contato" do painel
  removidos; o componente não lê nem escreve mais `ai_enabled`/`ai_mode` (o `saveEdit` parou de
  gravá-los). **Colunas DORMENTES** (padrão `warmup_enabled`): não removidas, **não zeradas**
  (provado por teste — editar o contato preserva valores antigos intactos).
- **Nuance registrada (dois campos, não um):** além do flag, existe `ai_mode`. O job continua
  lendo-o como sempre (mudar seu consumo não era autorizado); com default `'intencao'` e **0
  contatos divergentes em produção**, o comportamento efetivo é uniforme. Se um dia quiser modo
  global configurável, é conversa futura.
- **Clareza no global:** a descrição da "IA classificadora" agora diz "Liga/desliga pra conta
  INTEIRA (padrão: desligada); contatos silenciados continuam fora. Precisa do robo ligado." —
  frase por-contato removida. Rótulo/posição/estado já eram claros (registrado; sem mudança
  inventada).
- **Default OFF preservado:** nada liga IA pra ninguém; só a composição e a apresentação mudaram.

## PARTE C — Kanban no `ClassifyWithAi`

O move entrou **dentro do helper `registrarSemResposta`** (choke point único dos 7 call sites):
mesmo mecanismo da Fatia 11 — `moveToColumnSlug('aguardando', account, jid, 'sem_resposta',
incoming.id, cause: 'sem_resposta')` em try/catch isolado (falha de Kanban **não** derruba o job).
**Idempotência com o ingest:** mesmo `event_type` + `event_ref` (id da mensagem) → o unique
`(card, event_type, event_ref)` do BoardEngine garante 1 transição mesmo se os dois caminhos
rodarem pra mesma mensagem (provado por teste). Nenhuma decisão da IA mudou (classificação, fila,
promoção — byte-idênticas).

## Recomendação: fila de revisão (`/revisao`) → Kanban (SEM implementar — decisão do dono)

**Recomendação: SIM, mover pra `aguardando` também quando a IA escala pra revisão.**
- **Prós:** escalar É pendência humana (a definição da coluna desde a fatia 11); hoje o card fica
  parado em `em_atendimento`/`novo` enquanto a pendência real está escondida na aba Revisão — o
  Kanban mente por omissão; o mecanismo já existe (mesmo `moveToColumnSlug`, `cause` próprio tipo
  `'revisao'`, `event_ref` = id da pendência).
- **Contras:** a pendência de revisão tem ciclo próprio (aprovar → envia → `resposta_enviada`
  moveria o card DE VOLTA pra `em_atendimento` — comportamento até desejável, mas precisa ser
  pensado); risco de duplicar sinalização (badge da sidebar de Revisão + coluna) e de o dono
  preferir "aguardando = sem resposta nenhuma" vs "aguardando = qualquer ação humana pendente".
- Se aprovado, é fatia pequena: 1 chamada best-effort no ponto que cria a pendência + testes.

## Ajustes deliberados em testes (2, um a um)

1. `AiFallbackTest::test_ia_off_no_contato_nao_chama_driver` →
   `test_flag_de_ia_por_contato_e_dormente_nao_bloqueia_o_driver`: asseria a composição antiga
   (flag off ⇒ 0 calls). Consolidação inverteu por design: flag dormente ⇒ driver RODA com global
   on (1 call). Kill switch global e mute seguem cobertos pelos testes vizinhos, intocados.
2. `KnowledgeModeTest::test_ia_off_no_contato_base_nem_e_consultada` →
   `test_flag_de_ia_por_contato_e_dormente_base_e_consultada`: idem para o modo conhecimento
   (0 → 1 answerCalls). O teste do mute (`test_contato_off_base_nem_e_consultada`) segue verde
   SEM alteração — prova de que o mute continua vetando.

## Testes novos (`tests/Feature/AiControlGlobalTest.php`, 8 casos)

- **Composição global:** global ON + contato SEM flag → `ClassifyWithAi` despacha (novo
  comportamento); global OFF + flag individual LIGADO → nunca despacha (flag ignorado) e o
  unmatched do ingest segue registrado (decisão preservada); **mute veta** mesmo com global ON;
  **isolamento A/B** (IA ON em A não afeta B).
- **Campos dormentes:** editar contato não escreve nem zera `ai_enabled`/`ai_mode` (valores
  antigos intactos); painel não exibe mais "IA para este contato".
- **Parte C:** IA sem resposta (sem candidatas; classificador-fake que EXPLODE se consultado —
  prova que nem foi chamado) → unmatched registrado + card em `aguardando` com
  `cause='sem_resposta'` e `event_ref` = incoming id; **idempotência** com o move do ingest (mesmo
  message id = 1 transição); **falha de Kanban isolada** (mock explode; job segue e registra o
  unmatched).

## Contagem de testes

| | testes | assertions |
|---|---|---|
| Antes | 813 | 3260 |
| Depois | **821** | **3276** |

Suíte inteira **sequencial**, tudo verde — zero regressão fora dos 2 ajustes listados.

## Confirmações explícitas

- **Mute (`auto_reply_mode`) intocado em comportamento** — nenhuma linha de semântica/pipeline/UI
  do silenciar mudou (o handoff da Fatia 5 continua dependendo dele; testes de handoff verdes sem
  alteração).
- **Default da IA segue OFF**; nenhuma conta/contato foi ligado.
- **Zero migration; colunas `ai_enabled`/`ai_mode` dormentes** (não removidas, não zeradas).
- Pipeline tocado só nos dois pontos autorizados: composição do `aiEligible` e o move best-effort
  no `registrarSemResposta`. Nenhuma outra decisão de resposta/classificação mudou.
- Fila de revisão → Kanban: **não implementado** (recomendação registrada acima).

## Commit
Local, sem push (Fabio empurra). Hash reportado na resposta.
