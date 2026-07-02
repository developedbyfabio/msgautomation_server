# Multi-tenant + Escada de Inteligencia (Kanban, Proativas, Segmentacao)

Status: **DESENHO aguardando aprovacao do Fabio. NADA implementado.** Zero codigo, zero
migracao, zero mudanca de config neste documento — robô em producao intocado. Baseline no
momento do desenho: suite **279 verdes** (sequencial), commit `a2b90f6` (IA Fatia 2).

**Correcao de premissa:** o pedido citava "Fluxos Fatia B (UI construtora) e Fatia C" como
pendencias. Elas **JA FORAM ENTREGUES** (commits `7dca97c` Fatia B, `b7b744d..daaef83` Fatia C:
construtor /fluxos, testador dry-run, guarda de segredo, detector fluxo×regra, avisos de arvore).
As pendencias reais hoje sao **IA Fatia 3** (fila de aprovacao + /revisao) e **IA Fatia 4**
("virar regra" + ajustes finos).

---

## PARTE 1 — Auditoria de prontidao multi-tenant

### 1.1 O que JA esta pronto (melhor do que se esperava)

O sistema foi construido "multi-tenant em mente" desde a Camada 1 (o seeder chama a conta de
"conta-ancora"). O grosso do modelo de dados e das queries ja e escopado:

| Area | Estado | Evidencia |
|---|---|---|
| Tabelas de dominio | **Todas com `account_id`** | channels, incoming_messages, auto_reply_settings (unique account_id = 1 linha/conta), contacts (unique account+jid), auto_reply_logs, auto_reply_rules, secrets (unique account+nome), groups (unique account+jid), flows, flow_sessions, ai_decisions, knowledge |
| Tabelas filhas | Escopadas via FK pai (cascade) | rule_triggers/responses/contacts/ai_examples, flow_nodes/options/triggers, knowledge_contacts |
| Queries de dominio | Filtram `account_id` consistentemente | RuleMatcher, FlowEngine (sessao/entrada), SecretVault, AntiBanGuard, GroupNameResolver, RuleConflictDetector, Conversas/Contatos/Regras/Fluxos/Senhas/Conhecimento (via `accountId()`) |
| Freios em Redis | **Chaves POR CONTA** | `Throttle`: `autoreply:{accountId}:min/day/lastsend/contact` |
| Idempotencia de entrada | Por instancia | `incoming_messages` unique(instance, evolution_message_id) — correto se instancia→conta for 1:1 |
| Config do robo | 1 linha por conta | `AutoReplySetting::firstOrCreate(['account_id' => ...])` em todo lugar |
| Envio | Instancia parametrica | `Sender` → `EvolutionDriver::sendText($channel->instance, ...)` — o canal do banco decide a instancia |

### 1.2 Lacunas (o mapa REAL de acoplamento single-account)

Classificacao: risco pro multi-tenant (CRITICO = vazamento/mistura entre contas; ALTO = quebra
funcional; MEDIO = incorreto mas contornavel; BAIXO = cosmetico) × esforco (P/M/G).

| # | Lacuna | Onde | Risco | Esforco |
|---|---|---|---|---|
| L1 | **Webhook nao identifica a conta**: `resolverAccount()` retorna sempre `Account::oldest()`; a `instance` vem no payload mas e ignorada na resolucao; `resolverChannel` ate cria canal novo com a conta-ancora | `ProcessIncomingWhatsappMessage::resolverAccount/resolverChannel` | **CRITICO** — mensagens da conta 2 seriam arquivadas (e respondidas!) na conta 1 | P |
| L2 | **Ancora `Account::oldest()` como "conta atual" implicita** em TODA a UI e comandos | Livewire: Contatos, Regras, Fluxos, Senhas, Conhecimento, Conversas, Configuracoes; comandos WhatsappSend, AuthSenha; seeder | **CRITICO** — com 2 contas, todo mundo ve/edita a conta 1 | M |
| L3 | **Badge global do robo sem filtro de conta**: `AutoReplySetting::query()->value('enabled')` le a primeira linha do banco | `resources/views/components/layouts/app.blade.php:2` | MEDIO (so exibicao, mas mente pro operador) | P |
| L4 | **Instancia Evolution hardcoded em config**: `services.evolution.instance` fixa o canal da UI de conexao e do gate | `EnsureWhatsappConnected`, `StatusConexao`, `Conexao`, `EvolutionApi` (construtor ja aceita override — bom gancho), `evolution:setup/qr/status` | ALTO — so existe 1 canal conectavel | M |
| L5 | **Webhook secret unico global** (1 token pra todas as instancias) | `VerifyWebhookSecret` + `services.webhook` | MEDIO — origem validada, mas nao amarra payload→conta (a L1 resolve a amarra; token por canal e defesa extra) | P |
| L6 | **Cota diaria do Gemini GLOBAL**: chave `gemini:calls:{dia}` sem conta; chave de API unica | `GeminiDriver::withinDailyCap`, `services.gemini` | ALTO — uma conta esgota a cota de IA de todas (cross-tenant por recurso) | P |
| L7 | **SECRETS_KEY unica** pra todos os cofres (valores escopados por conta no banco, cifra compartilhada) | `config/secrets.php`, `SecretCipher` | MEDIO — vazamento exige vazar a chave DO SERVIDOR; aceitavel na fase 1, chave por conta e endurecimento futuro | M |
| L8 | **Auth single-user**: `users` sem vinculo com `accounts`, sem papeis, sem policies; `msg:auth:senha` opera no `User::oldest()` | users migration, `Login`, `AuthSenha`, `SingleUserSeeder` | ALTO — pre-requisito de multi-user | M |
| L9 | **Sem guarda ESTRUTURAL de isolamento**: o escopo por conta e disciplina manual de query (funcionou ate aqui, mas nada impede a proxima feature de esquecer um WHERE) | todo o app | **CRITICO como risco latente** | M |
| L10 | **Fila unica `default`, 1 worker**: sem vazamento (jobs carregam ids), mas sem fairness — backlog de uma conta atrasa as outras; e proativas/IA na mesma fila do webhook | systemd `msgautomation-worker` | MEDIO (fase 1-3 contas: ok) | P/M |
| L11 | **`evolution:setup/qr/status` e webhook_url** assumem 1 instancia | comandos + `services.evolution.webhook_url` | ALTO pro onboarding de conta nova | M |
| L12 | **Gemini API key unica** (custo/limite compartilhado; free tier por projeto Google) | `services.gemini.api_key` | MEDIO — fase 1: cota LOCAL por conta (L6); chave por conta = coluna cifrada futura | P |

Nao-lacunas (conferidas e ok): `channels.instance` unique global (correto: nomes de instancia
sao unicos no servidor Evolution compartilhado); sessions em database (multi-user nativo);
`auto_reply_logs.incoming_message_id` unique (idempotencia de resposta, ja por mensagem);
Throttle por conta; dedupe de resolucao de grupo por conta.

### 1.3 Guarda estrutural proposta (a peca central)

1. **`AccountContext`** (servico): resolve a conta corrente — na WEB, do usuario logado
   (membership, ver Parte MT-2); em JOBS, explicito por id carregado no job (ja e assim); em
   CLI, por opcao `--account`. Proibido "oldest()" como fallback fora do bootstrap da conta 1.
2. **Trait `BelongsToAccount` + global scope**: todo model de dominio ganha
   `where account_id = AccountContext::id()` automatico quando ha contexto, e `account_id`
   preenchido no `creating`. Jobs setam o contexto explicitamente antes de tocar em models.
   (Global scope e rede de seguranca; as queries explicitas atuais continuam — cinto E
   suspensorio.)
3. **Policies** por model (view/update = mesma conta do usuario) quando entrar multi-user.
4. **Teste de isolamento cruzado OBRIGATORIO em toda fatia** (gate de CI local): helper de
   teste que cria DUAS contas com dados espelhados e prova que (a) UI/queries da conta A nao
   retornam NADA da conta B; (b) webhook da instancia B nunca grava/aciona na conta A; (c)
   freios da conta A nao consomem contadores da B. Vira classe base `TenantIsolationTest` +
   casos por feature.

### 1.4 Ordem segura de migracao (sempre aditiva, conta unica continua rodando)

- **MT-0 — Scoping estrutural com a conta unica atual** (sem mudanca de comportamento):
  `AccountContext` + trait/global scope + corrigir L1 (conta via `channels.instance` do
  payload — o canal ja liga instancia→conta), L3 (badge por conta), L6 (chave de cota
  `gemini:{accountId}:calls:{dia}`), L5 (token por canal, coluna `webhook_token` em channels).
  Testes de isolamento com 2 contas sinteticas NO TESTE (producao segue 1 conta).
- **MT-1 — Multi-usuario**: pivot `account_user` (user_id, account_id, role dono|operador),
  policies, seletor de conta na sessao (pra usuario com N contas), `msg:auth:senha --email`.
  Producao: Fabio vira dono da conta 1. Nada muda pro robô.
- **MT-2 — Multi-instancia/canal por conta**: CRUD de canal (nome da instancia gerado por
  conta), `EvolutionApi` por canal (ja aceita overrides no construtor), `evolution:setup`
  por canal, `EnsureWhatsappConnected`/`StatusConexao`/`Conexao` lendo o canal DA CONTA
  corrente, webhook_url unico (a conta sai do payload `instance` — MT-0 ja garantiu).
- **MT-3 — Onboarding**: criar conta nova pela UI (dono) → cria settings default (tudo OFF)
  + canal + QR na /conexao da conta. Gate manual do Fabio pra cada conta nova.
- **Infra junto**: MT-0/1 nada muda; MT-2+ adiciona filas nomeadas (`default`, `ai`,
  `proactive`) e um segundo worker systemd (`--queue=ai,proactive`); Redis e MySQL atuais
  aguentam N pequenas contas (medidos na Fase 0: folga grande). Producao "de verdade"
  (php-fpm+nginx, HTTPS pro webhook) so quando sair da LAN — registrado como horizonte.

---

## PARTE 2 — Escada de Inteligencia (formalizacao de produto)

Conceito: cada conversa sobe a escada só ate onde a conta configurou. Tudo que ja existe
permanece identico; os degraus novos OBSERVAM e INICIAM, nunca reescrevem o pipeline reativo.

| Nivel | Nome | Status | Reusa | Novo |
|---|---|---|---|---|
| N0 | Manual (/conversas) | ENTREGUE | — | — |
| N1 | Regras deterministicas | ENTREGUE (precedencia por especificidade) | — | — |
| N2 | Fuzzy/tolerante | ENTREGUE (precision por gatilho) | — | — |
| N3 | Fluxos/menus com sessao | ENTREGUE (motor + construtor + testador) | — | — |
| N4 | IA casa regra (intencao) | ENTREGUE (Fatia 1) | — | — |
| N5 | IA base de conhecimento | ENTREGUE (Fatia 2) | — | — |
| N6 | Escalar/aprovacao + virar regra | **ENTREGUE** (Fatias 3 e 4 — arco da IA completo) | ai_decisions, Sender | pending_approvals, /revisao, promotor de regra |
| N7 | Kanban dirigido por conversa | DESENHO (abaixo) | eventos dos logs atuais | boards/cards/transicoes |
| N8 | Proativas com gate | **ENTREGUE** (P-1..P-3; reativacao por tempo = P-4 futura) | Sender/freios/filas | campanhas, scheduler, freios proprios |
| N9 | Segmentacao/tags | **ENTREGUE** (T-1) | escopo de contatos | tags manuais+automaticas |
| N10 | Metricas | **ENTREGUE** (M-1) | logs existentes | /painel agregado |

### N7 — Kanban dirigido por conversa

**Ideia:** cada contato/conversa e um card num board da conta; o card se MOVE SOZINHO por
eventos que o pipeline ja produz, e o Fabio tambem move na mao. O Kanban NAO decide resposta —
e um OBSERVADOR do pipeline + um estado consultavel (que N8/N9 usam).

**Modelo (aditivo):**
- `boards` (account_id, name, default bool) — fase 1: 1 board por conta (schema ja permite N).
- `board_columns` (board_id, name, position, `system_role` opcional: novo|ativo|aguardando|
  fechado|reativacao — papel semantico pra automacoes, nome livre).
- `cards` (account_id, board_id, column_id, contact_id, unique(board_id, contact_id),
  last_event_at, timestamps) — card por CONTATO (conversa 1:1; grupos fora do Kanban, como ja
  sao pulados no robô).
- `card_transitions` (card_id, from_column_id, to_column_id, trigger: evento|manual|tempo,
  detalhe, timestamps) — historico completo.
- `board_rules` (board_id, event_type, condicao JSON, to_column_id, enabled) — regras de
  movimento CONFIGURAVEIS na UI: "evento X (com condicao) → coluna Y".

**Captura de eventos SEM reescrever o pipeline:** os pontos de escrita ja existem; a proposta
e emitir **eventos de dominio Laravel** (síncronos, baratos) nesses pontos e tratar num
listener enfileirado (`queue=default`) que aplica `board_rules`:
- `IncomingMessage` persistida (contato falou) → evento `ContatoFalou`.
- `auto_reply_logs` status=sent mode=auto (robô respondeu; rule_id/flow) → `RoboRespondeu`.
- `auto_reply_logs` mode=manual (humano respondeu) → `HumanoRespondeu`.
- `flow_sessions` transicao de no / status completed|cancelled → `FluxoAvancou(no X)`.
- `ai_decisions` criada (acao respondeu|escalou|silenciou, intent, origem) → `IaDecidiu`.
- "Tempo sem resposta na coluna Y ha X horas/dias" → **avaliado pelo scheduler do N8** (um
  unico agendador serve N7-timeout e N8-proativas), gerando `TempoEstourou`.
Cada emissao carrega account_id + contact/jid + payload minimo. Board_rules exemplo: "IA
escalou → coluna Aguardando"; "fluxo chegou no no 'orcamento' → coluna Em atendimento";
"HumanoRespondeu → Em atendimento"; "TempoEstourou(7d, coluna Fechado) → Reativacao".

**UI:** pagina `/kanban` (Livewire, padrao atual): colunas com cards (nome do contato, ultima
mensagem/preview mascarado, badges de tag/IA), mover manual (dropdown "mover para" na fase 1;
drag-and-drop e polimento), historico do card (transicoes), e editor de `board_rules` com os
eventos disponiveis num select (sem codigo). Colunas padrao sugeridas: **Novo, Em atendimento,
Aguardando resposta, Resolvido, Reativacao.**

**Dependencias:** nenhuma dura das pendencias de IA (eventos de ai_decisions ja existem);
MT-0 antes (cards nascem escopados/testados). Fluxos B ja entregue (gatilho por no funciona).

### N8 — Proativas (follow-up, lembrete, reativacao) — MAIOR RISCO DE BAN

**Principio:** proativa NUNCA responde — INICIA. O reativo continua identico. Tudo passa pelo
MESMO `Sender`, num modo novo `proactive` com freios PROPRIOS **mais duros** que os reativos,
alem dos tetos protetivos globais que ja valem pra todo envio:

1. **Kill switch proprio** (`proactive_enabled`, default OFF) — separado do robô e da IA.
2. **Gate humano POR CAMPANHA**: campanha nasce `draft` → preview da lista EXATA de
   destinatarios + mensagem renderizada → botao "Aprovar" (so dono) → `approved` → scheduler
   agenda. Sem aprovacao, NADA dispara. Editar campanha aprovada volta pra draft.
3. **Opt-in explicito por contato** (`contacts.proactive_opt_in`, default false; setado na mao
   ou por fluxo em que o contato CONFIRMOU receber — registro do consentimento com data/fonte,
   tambem por LGPD). Sem opt-in, o contato nem entra em preview.
4. **Teto diario proprio e BAIXO** por conta (default sugerido: 20/dia) + **limite por
   contato** (1 proativa/semana, qualquer campanha) + dedupe por campanha+contato.
5. **Janela propria** (default 09-18h), espacamento aleatorio grande entre envios (jitter
   3-15 min — nada de rajada), e re-check volatil antes do POST (R2 igual ao reativo:
   opt-in ainda vale? kill switch ainda on? contato nao virou 'off'?).
6. **Opt-out imediato**: resposta do contato tipo "parar/sair" (regra determinística de
   sistema) desliga opt-in e loga; QUALQUER resposta do contato encerra o fluxo proativo dele
   (vira conversa reativa normal).

**Modelo (aditivo):** `campaigns` (account_id, nome, mensagem template — placeholders ok,
`{senha:}` PROIBIDO em proativa, guarda dura —, segmento: colunas do Kanban e/ou tags e/ou
condicao de tempo, status draft|approved|running|done|paused, approved_by/at);
`campaign_targets` (campaign_id, contact_id, status pending|sent|skipped+motivo, sent_log_id);
settings da conta ganham bloco proativo (kill switch, teto/dia, janela, limite/contato/semana).

**Scheduler:** `schedule:work` do Laravel (novo unit systemd `msgautomation-scheduler`, ou
cron a cada minuto) roda dois avaliadores idempotentes e baratos: (a) `TempoEstourou` do
Kanban (N7); (b) campanhas `approved` → enfileira `SendProactive` na fila `proactive` com
delays aleatorios respeitando teto/janela — o job re-checa TUDO de novo no envio (estado pode
mudar entre agendar e enviar). Sem loop novo de infra: e a fila atual + 1 processo agendador.

**Dependencias:** N7 (segmento por coluna/tempo), N9 (segmento por tag), MT-0. Freios novos
testados como os atuais (mock, nunca envio real; mesma disciplina).

### N9 — Segmentacao e tags

- `tags` (account_id, nome, cor) + pivot `contact_tag` (+ origem: manual|evento|intent, data).
- Automaticas: acao "aplicar/remover tag" disponivel nas `board_rules` do N7 (mesmo motor de
  eventos: "IA classificou intent=orcamento → tag 'quer-orcamento'"). Manuais: chips no painel
  do contato (/contatos e /conversas).
- Uso: escopo de regras/fluxos ganha alternativa "por tag" (hoje e lista de contatos); segmento
  de campanha proativa por tag; filtro no Kanban e nas metricas. Aditivo: escopo atual por
  contato continua valendo.

### N10 — Metricas

Pagina `/painel` (Livewire + SQL agregado + componentes atuais; ZERO lib nova): mensagens
recebidas/enviadas por dia; respostas por nivel (manual × regra × fluxo × IA-regra × IA-base —
tudo derivavel de `auto_reply_logs` + `ai_decisions.origem`); decisoes da IA (respondeu/
escalou/silenciou por motivo); funil do Kanban (cards por coluna, conversoes por
`card_transitions`); tempo de resposta (received_at → sent_at); proativas (enviadas × opt-outs).
Graficos simples em barra/linha com SVG/Tailwind (padrao msg-preview) ou tabela — decidir no
gate da fatia. Dependencia: nenhuma (versao 1 ja da valor so com os logs atuais).

---

## PARTE 3 — Precedencia e seguranca revisadas

**Precedencia reativa INTOCADA:** fromMe → grupo → portao do contato → sessao de fluxo ativa →
fluxo de entrada → regra por especificidade → IA (intencao → conhecimento) → silencio. O Kanban
e OBSERVADOR (consome eventos DEPOIS que a decisao aconteceu; nunca altera a resposta). As
proativas sao INICIADORAS fora desse pipeline (scheduler → Sender modo `proactive`); se o
contato responde, a resposta entra pelo pipeline reativo normal — que continua mandando.
Anti-colisao minima: proativa nao dispara pra contato com sessao de fluxo ATIVA (nao atropela
menu no meio) e marca `last_event_at` no card.

**Freios por conta:** cada conta com seus tetos/janela/kill switches (`auto_reply_settings` ja
e 1 linha/conta; bloco proativo idem). `Throttle` ja e por conta. Instancia Evolution POR
CONTA = numero, sessao e risco de ban isolados por construcao — uma conta banida nao derruba
outra (e o servidor Evolution e um so, mas sessoes/instancias sao independentes).

**Cofre e IA por conta:** `secrets`/`knowledge`/`ai_decisions`/aprovacoes ja tem account_id.
Endurecimentos do desenho: cota Gemini por conta (L6, MT-0); chave Gemini por conta como
coluna cifrada opcional (fallback pra global) quando houver tenant real; SECRETS_KEY global na
fase 1 (aceito) com rotacao/por-conta como horizonte (L7). IA nunca ve dado de outra conta por
construcao (payload montado de queries escopadas — e o teste de isolamento prova).

**LGPD (desenho, nao build):** dados de contato/mensagem pertencem a CONTA. Por conta:
exportacao (dump JSON/CSV de contacts + incoming_messages + logs da conta), limpeza/expurgo
(apagar conta → cascade ja apaga tudo dela; retencao configuravel de `raw_payload` e um
horizonte), registro de consentimento das proativas (origem/data do opt-in em
`campaign_targets`/contato). Base já isolada: `cascadeOnDelete` de account em todas as tabelas.

---

## PARTE 4 — Plano de fatias sequenciado

Regra geral: fatias pequenas, migrations aditivas, tudo OFF por default, teste de isolamento
cruzado em TODA fatia a partir de MT-0, gate do Fabio no fim de cada uma.

| # | Fatia | Escopo curto | Depende | Risco | Gate do Fabio |
|---|---|---|---|---|---|
| 1 | **IA-3** Fila de aprovacao | **ENTREGUE** — `pending_approvals` + /revisao (Enviar/Editar/Ignorar); escalou vira item acionavel | — | Baixo | Testar aprovar/editar/ignorar num contato real |
| 2 | **IA-4** Virar regra | **ENTREGUE** — promocao a regra/entrada da base; limiar/temas editaveis em /configuracoes | IA-3 | Baixo | Validar 1 promocao e o efeito (proxima msg gratis) |
| 3 | **MT-0** Scoping estrutural | **ENTREGUE** — AccountContext + BelongsToAccount/global scope + L1 (conta via instance) + L3 + L5 (token/canal) + L6 (cota IA/conta) + TenantIsolationTest | — | Medio (toca o miolo; zero mudanca de comportamento visivel) | Suite verde + isolamento provado; robô identico |
| 4 | **K-1** Kanban modelo+eventos | **ENTREGUE** — boards/columns/cards/transitions + eventos de dominio nos pontos de escrita + board_rules padrao aplicando | MT-0 | Baixo | Ver cards se movendo sozinhos com regras default |
| 5 | **K-2** Kanban UI | **ENTREGUE** — /kanban board + mover manual + historico + editor de colunas e board_rules | K-1 | Baixo | Usar o board 1 semana; ajustar colunas |
| 6 | **T-1** Tags | **ENTREGUE** — tags + pivot com origem + chips na UI + acoes de tag nas board_rules + escopo por tag em regras/fluxos | K-1 | Baixo | Criar 2-3 tags reais e uma regra escopada |
| 7 | **P-1** Proativas: freios+opt-in | **ENTREGUE** — bloco proativo nas settings (tudo OFF), opt-in + trilha de consentimento + opt-out por palavra + ProactiveGuard | MT-0 | Medio | Aprovar defaults dos tetos |
| 8 | **P-2** Campanhas com gate | **ENTREGUE** (draft→preview→aprovar + agenda com jitter; SEM disparo — tick/job = P-3) | P-1, K-1, T-1 | **Alto (ban)** | Aprovar a PRIMEIRA campanha com 2-3 contatos de teste (numeros do Fabio) |
| 9 | **P-3** Disparo real | **ENTREGUE** — tick + SendProactiveMessage (claim atomico + guard + R2 + release) + estados running/done/paused + opt-out no meio; ARCO PROATIVO COMPLETO aguardando gate do Fabio (checklist em docs/relatorios/2026-07-02-p3.md). Reativacao por tempo via Kanban (TempoEstourou) fica como fatia futura P-4 | P-2, K-2 | Alto (ban) | Checklist de manha: 1o disparo real controlado |
| 10 | **M-1** Metricas | **ENTREGUE** — /painel com agregados dos logs + funil do Kanban (leitura pura, cache 60s) | K-1 (funil; resto independe) | Baixo | Validar numeros contra a realidade |
| 11 | **MT-1** Multi-usuario | account_user (dono/operador) + policies + seletor de conta + auth ajustada | MT-0 | Medio | Criar o 1o operador |
| 12 | **MT-2** Canal por conta | CRUD canal + EvolutionApi por canal + conexao/QR por conta + setup por canal | MT-0 (MT-1 recomendado antes) | Medio | Conectar um 2o numero de teste |
| 13 | **MT-3** Onboarding de conta | Criar conta nova na UI (settings OFF + canal + QR) | MT-1, MT-2 | Medio | Abrir a conta 2 real |

Observacoes de sequencia:
- IA-3/IA-4 primeiro: sao curtas, fecham divida viva (escalados hoje morrem no log) e a fila
  de aprovacao e REUSADA pelas proativas (mesmo padrao de gate humano).
- MT-0 vem ANTES de qualquer modelo novo (Kanban/tags/campanhas ja nascem escopados e
  testados contra vazamento — retrofit depois custa o dobro).
- MT-1..MT-3 (multi-user/multi-instancia/onboarding) NAO bloqueiam a escada de inteligencia:
  entram quando o Fabio decidir abrir a conta 2. Se a prioridade virar "multi-tenant ja",
  basta puxar 11-13 pra frente de 4 — nada do desenho muda.
- Paralelismo possivel: M-1 e T-1 sao independentes entre si; K-2 e T-1 podem trocar de ordem.

---

## PARTE 5 — Decisoes numeradas pro Fabio

**D1 — Ordem dos arcos.** Recomendo: **IA-3 → IA-4 → MT-0 → Kanban (K-1, K-2) → Tags →
Proativas (P-1..P-3) → Metricas → MT-1..MT-3 quando for abrir a conta 2.** Justificativa:
IA-3/4 sao pendencia curta e o /revisao e reusado pelo gate das proativas; MT-0 e barato e
evita retrofit dos modelos novos; multi-user/multi-instancia so pagam quando houver tenant
real. (Correcao: Fluxos B e C ja estao entregues — nao ha pendencia de fluxos.)
Alternativa se a conta 2 for iminente: MT-0 → MT-1..MT-3 antes do Kanban.

**D2 — Modelo de canal.** Recomendo **1 instancia Evolution POR CONTA no MESMO servidor
Evolution (Docker 8090)**. Isolamento de sessao/ban por construcao, custo marginal minimo
(instancias sao leves; servidor medido com folga), sem novo container por conta. Webhook
continua um endpoint so; a conta sai do `instance` do payload (L1) + token por canal (L5).
Contra-opcao (instancia compartilhada entre contas) e vetada: mistura numero/risco/contatos.

**D3 — Usuarios.** Fase 1: **1 usuario (dono) por conta**, com o schema ja preparado pra
N (pivot account_user com role dono|operador). Papel operador (sem ver cofre/configuracoes,
p.ex.) entra em MT-1.1 quando houver necessidade real. Evita construir RBAC antes de existir
o segundo humano.

**D4 — Kanban.** Fase 1: **1 board por conta** (schema permite N; UI de multi-board so se a
pratica pedir). Colunas padrao: **Novo, Em atendimento, Aguardando resposta, Resolvido,
Reativacao** (nomes editaveis; `system_role` semantico por tras). Card por CONTATO, grupos
fora (coerente com o robô que ja pula grupos).

**D5 — Proativas.** Defaults conservadores propostos: kill switch proprio **OFF**; teto
**20 proativas/dia por conta**; **1 por contato por semana** (todas as campanhas somadas);
janela **09h-18h**; jitter **3-15 min** entre envios; **opt-in explicito obrigatorio**
(campo no contato, setado na mao ou por confirmacao em fluxo, com registro de origem/data);
opt-out por palavra ("parar"/"sair") desliga na hora; `{senha:}` proibido em proativa.
Gate por campanha: **draft → preview com a lista EXATA de destinatarios e a mensagem final →
aprovacao do dono → agendamento**; editar = volta pra draft; pausar a qualquer momento.

**D6 — Fora por ora (horizonte registrado, nao esquecido):** cobranca/planos/billing;
API oficial WhatsApp Cloud; canvas visual de fluxos (arvore-outline atende); exposicao
publica/HTTPS/fpm+nginx (enquanto LAN); portal do cliente final; midia em proativas
(so texto na fase 1); chave Gemini e SECRETS_KEY por conta (endurecimento pos-MT-3);
websockets/paginacao do /conversas (pendencia antiga de UI, independente deste desenho).

---

*Desenho produzido em 2026-07-02 sobre o commit `a2b90f6`, suite 279 verde. Proximo passo:
aprovacao/ajuste das decisoes D1-D6 pelo Fabio; nada sera implementado antes disso.*

---

## MT-0 — ENTREGUE (2026-07-02)

Zero mudanca de comportamento da conta unica (suite anterior de 320 passou sem alteracao de
expectativa; 3 ajustes de SETUP de teste refletindo producao — canal/conta que o seeder sempre
cria). Suite final: **333 verdes** (incl. o gate novo). Webhook vivo validado pos-deploy.

**Lacunas fechadas:**
- **L1** — webhook resolve a conta pelo CANAL da instancia do payload (dado real validado antes:
  `channels.instance='fabio-pessoal'` bate com 100% dos payloads recentes; nenhuma correcao de
  dado necessaria). Instancia desconhecida: `Log::warning` + contador em cache
  (`webhook:instancia_desconhecida:{dia}` e `:ultima`) e DESCARTA — nunca cai em outra conta.
- **L2** — `Account::oldest()` eliminado do dominio: o unico fallback de conta unica vive no
  `AccountContext` (config `tenancy.single_account_fallback`, fase 1 = true). Telas, layout e
  comandos usam o contexto.
- **L9** — guarda estrutural: trait `BelongsToAccount` (global scope `AccountScope` + `creating`
  injeta `account_id`) em TODOS os 13 models com a coluna: Contact, AutoReplyRule,
  AutoReplySetting, AutoReplyLog, IncomingMessage, Channel, Secret, Group, Flow, FlowSession,
  AiDecision, Knowledge, PendingApproval (filhas sem a coluna herdam escopo via FK do pai).
  Sem contexto e sem fallback: `MissingAccountContextException` — FALHA ALTO, nunca vaza em
  silencio (provado por teste). Bypass SO nomeado: `Model::withoutAccountScope()` — usos:
  webhook (canal por instancia), jobs (carregar o proprio registro pra restaurar contexto),
  `EnsureWhatsappConnected` (gate por instancia, fase 1), `VerifyWebhookSecret` (token),
  `AntiBanGuard` (API por parametro: settingsFor/contactMode/aiContactEnabled/logs de cooldown).
- **L6** — cota diaria do Gemini POR CONTA (`ai:{accountId}:gemini:calls:{dia}`); demais freios
  ja eram por conta (Throttle `autoreply:{accountId}:*`, dedupe de grupo) — validados no gate.
- **L5 (parcial, retrocompat)** — `channels.webhook_token` (unico, gerado pros canais existentes)
  + rota nova `/webhook/evolution/{token}`. A URL ATUAL (header secret global) segue valendo,
  marcada **DEPRECADA** — a Evolution NAO foi reconfigurada nesta fatia.

**Propagacao de contexto:** middleware `SetAccountContext` (grupo web; MT-1 troca a fonte pro
usuario logado — ponto unico); jobs serializam `account_id` e restauram no handle (com fallback
defensivo pro proprio registro, cobrindo jobs enfileirados durante o deploy);
`Queue::before` limpa o contexto entre jobs (worker longevo nunca herda conta do job anterior);
`ai:expire-approvals` itera todas as contas com `runAs` (`--account` restringe).

**Gate permanente:** `TenantIsolationTest` (13 testes, contas ESPELHADAS — mesmo jid/gatilho/
nome de secret): webhook por instancia responde so com dados da conta certa, secret homonimo
resolve o valor certo, telas listam so o contexto, job de IA nao cruza (payload do modelo
minimizado por conta), teto/cota/kill switch por conta, excecao sem contexto, creating injeta,
bypass e o unico caminho cross-account, token por canal + retrocompat.
**TODA fatia futura roda e ESTENDE este teste.**

**Passo futuro (MT-2/onboarding) — migracao da URL do webhook:** trocar a URL configurada na
Evolution de `/webhook/evolution` (header secret global) para `/webhook/evolution/{token-do-canal}`
por instancia (`evolution:setup` por canal), validar trafego no novo caminho e so entao remover
o caminho retrocompat do `VerifyWebhookSecret`. Mexe em config viva de producao: fazer com o
Fabio no gate da MT-2.

**Proxima fatia da ordem (D1):** Kanban **K-1** (modelo + eventos).

---

## K-1 — ENTREGUE (2026-07-02)

Motor HEADLESS do Kanban (N7), observador puro: assiste eventos e move cards; nunca envia,
nunca decide resposta, nunca altera o pipeline (falha de listener e ISOLADA — try/catch + log —
e a suite anterior de 333 passou intacta). Suite final: **347 verdes**.

**Schema (migration `000026`, aditivo, escopado):** `boards` (default por conta), `board_columns`
(slug ESTAVEL: novo/em_atendimento/aguardando/resolvido/reativacao — D4), `cards` (UNIQUE
contato+board; last_interaction_at/last_direction), `card_transitions` (historico com causa:
regra|manual|tempo + board_rule_id + event_type + event_ref; UNIQUE card+evento+ref =
idempotencia de re-entrega), `board_rules` (evento + condicoes JSON + coluna destino, first-match
por position; prontas pra UI da K-2). `BoardProvisioner` cria o board default (colunas D4 +
regras minimas) — migration cobriu a conta existente; `Account::created` cobre contas futuras.

**Eventos de dominio (dispatch nos pontos de escrita existentes):**
- `IncomingMessageStored` — ProcessIncomingWhatsappMessage::handle (apos popularContato;
  fromMe/grupos FORA por construcao);
- `AutoReplySent` / `ManualMessageSent` — Sender::send, SO no envio efetivo (status sent);
  'aprovacao' conta como AutoReplySent;
- `FlowNodeReached` — FlowEngine::emit (start/advance); `AiDecisionRecorded` — ClassifyWithAi
  (ambos SEM regra default; disponiveis pra K-2/tags).
Listener UNICO em fila (`UpdateKanbanFromEvent`, ShouldQueue) -> `BoardEngine`.

**Regras default (editaveis na K-2):** 1) mensagem_recebida sem card -> cria em NOVO;
2) mensagem_recebida com card em Resolvido -> volta pra NOVO (reabertura); 3) resposta_enviada
fora de Em atendimento -> move pra EM ATENDIMENTO; 4) envio_manual idem. Sem regra por tempo
(reativacao = arco das proativas); coluna Reativacao existe sem automacao.

**Aprendizado estrutural (MT-0 corrigido de tabela):** a higiene de contexto da fila virou
PILHA (push/pop em Queue::before/after/exceptionOccurred) — com fila sync (testes/dispatchSync),
listener/job aninhado nao apaga mais o contexto do job pai.

**Gate estendido:** TenantIsolationTest agora prova tambem: contas espelhadas (mesmo jid) geram
cards SEPARADOS, evento da A move so card da A, boards/cards/regras da B invisiveis no contexto A.

**K-2 (proxima):** UI do board (/kanban), movimento manual com historico, edicao de colunas e
board_rules na tela (estrutura ja pronta), badges/contadores.

---

## K-2 — ENTREGUE (2026-07-02)

UI do Kanban em `/kanban` (item no menu com badge = cards em "Novo"), no padrao das telas
(Livewire + Flux free, modais, tooltips, polling 15s). Observador puro INTACTO: mover card e
acao humana (cause=manual); a tela nunca envia nem decide (provado por teste — mover nao gera
POST). Suite final: **361 verdes** (347 anteriores sem mudanca de expectativa).

- **Board:** colunas lado a lado (scroll horizontal; header fixo + scroll interno por coluna),
  contador por coluna, busca por contato, card com nome/tempo relativo/direcao (in/out).
  Clique no card abre a conversa: `/conversas?jid=...` (mount do Conversas aceita o query param).
- **Movimento manual:** menu "Mover para..." no card (SEM drag-and-drop — sem lib nova; a mesma
  decisao do editor de fluxos outline; drag-drop registrado como melhoria futura). Mesma coluna =
  no-op. Historico do card em modal (transicoes com causa regra|manual + evento + data).
- **Colunas:** renomear (slug ESTAVEL — regras nunca quebram, testado com evento pos-renomeacao),
  reordenar (setas), adicionar custom (slug proprio gerado). Excluir: SO custom + vazia + sem
  regra apontando; as 5 system da D4 nao sao excluiveis (tooltip explica).
- **Regras de movimento:** lista em ordem com first-match explicado; ativar/desativar, reordenar,
  editar/criar (evento entre os 5 emitidos com descricao pt-BR — incl. fluxo_no e ia_decisao sem
  default; condicoes basicas; destino obrigatorio e do board da conta). Mexer em regra DEFAULT
  (`board_rules.is_default`, migration 000027) pede confirmacao leve. Tudo vale so pra eventos
  futuros (tooltip; nada reprocessa historico).
- **Gate estendido:** tela da A nao mostra nem move cards da B (mover/historico de card alheio =
  no-op).

**Melhoria futura registrada:** drag-and-drop no board (exige lib JS; menu atende).
**Proxima fatia da ordem (D1): T-1 (tags).**

---

## T-1 — ENTREGUE (2026-07-02)

Tags como camada de SEGMENTACAO (N9): nunca enviam nada. Pre-requisito da segmentacao das
proativas (P-fatias). Suite final: **378 verdes** (361 anteriores sem mudanca de expectativa).

**Schema (migration `000028`, aditivo, escopado):** `tags` (nome UNICO por conta, cor da paleta
de badges), pivo `contact_tag` (UNIQUE contato+tag = idempotencia; ORIGEM rastreada: manual |
board_rule | ai_intent + origin_ref), pivos de escopo `rule_tag`/`flow_tag`, e `board_rules` +=
`action_type` (move_column default | add_tag | remove_tag) + `tag_id` (to_column_id virou
nullable). Regras existentes viram move_column (identico).

**SEMANTICA DO MOTOR (documentada e testada):** `move_column` segue FIRST-MATCH (so a primeira
regra de coluna que casa move); `add_tag`/`remove_tag` sao CUMULATIVAS (todas as que casam
aplicam, antes ou depois do move). Condicao nova `{"intent": "x"}` (so evento ia_decisao): casa
quando a IA RESPONDEU (acima do limiar) com o intent — origem do pivo vira `ai_intent`/`intent`.
Tag excluida: pivos caem (cascade), board_rules de tag ficam inertes (tag_id null), regras/
fluxos com a tag como ESCOPO ficam sem alcance ate ajuste (aviso com contagem de uso no modal).

**Escopo por tag em regras e fluxos:** terceira opcao "Contatos com tag" (casa quem tem QUALQUER
uma; avaliado NA HORA do match — tag entra/sai, alcance muda na proxima mensagem).
**Especificidade atualizada: contatos especificos (2) > tag (1) > global (0)** — demais
desempates intactos. Detector de conflito ja cobre tag×global e tag×especifica (ignora escopo
de proposito; provado por teste). Testador explica "casou por TAG" e "gatilho casaria, mas o
contato NAO tem a tag".

**GUARDA S5 (dura):** regra que devolve `{senha:}` NAO pode usar escopo por tag — tag e
DINAMICA (um evento pode aplica-la a qualquer contato); segredo exige lista explicita.
Bloqueado no RuleWriter E na UI com mensagem clara. Fluxo com `{senha:}` segue exigindo
"Contatos Especificos" pra ligar (tag/global nao valem). Base de conhecimento nao tem escopo
por tag (segue so com contatos explicitos — guarda existente intacta).

**UI:** chips com cor no painel do contato (/contatos e /conversas — componente reutilizavel
`contact-tags` com autocomplete + criar na hora + remover, tooltip com origem/data); "Gerenciar
tags" em /contatos (renomear/cor/excluir com uso e confirmacao); Kanban com chips no card +
filtro por tag; editor de regras do Kanban com select de ACAO (coluna OU tag) + condicao por
intent (descricoes pt-BR).

**Gate estendido:** tags homonimas em contas espelhadas nao se cruzam (regra por tag da A nao
casa contato da B com tag homonima); acao de tag do board da A nao toca contato da B.

**Proxima fatia da ordem (D1): P-1 — freios das proativas (bloco proativo nas settings, tudo
OFF; opt-in explicito por contato com registro de consentimento; opt-out por regra de sistema).**

---

## P-1 — ENTREGUE (2026-07-02)

A JAULA das proativas — NENHUM caminho de envio proativo existe (provado por
Http::assertNothingSent em tudo que a fatia toca). Tudo nasce OFF com os defaults D5 exatos.
Suite final: **402 verdes** (378 anteriores sem mudanca de expectativa).

**Schema (migration `000029`, aditivo):** `auto_reply_settings` += bloco proativo
(`proactive_enabled` OFF — kill switch PROPRIO, independente do reativo —, `daily_cap` 20,
`per_contact_weekly_cap` 1, janela 09-18h SP, jitter 3-15min pro scheduler da P-2,
`optout_word` "PARAR"); `contacts.proactive_opt_in` (false); `proactive_consents` — trilha
AUDITAVEL de grant/revoke com origem (manual | palavra), NUNCA apagada (LGPD: prova do
consentimento e da revogacao).

**ProactiveGuard** (`app/Whatsapp/Proactive/`) — API por parametro, 9 freios NA ORDEM (barato
primeiro; check nao gasta contador):
a) `proactive_off` · b) `grupo` · c) `opt_out` (off do robo JAMAIS recebe proativa) ·
d) `sem_opt_in` · e) `fluxo_ativo` (nao atropela conversa) · f) `fora_da_janela_proativa` ·
g) `teto_dia_proativo` (conta, dia SP) · h) `teto_semana_contato` (conta+contato, semana ISO SP) ·
i) `contem_senha` — **{senha:} PROIBIDO em proativa, sem excecao**.
Contadores em cache com **check (leitura) vs claim (consumo ATOMICO com rollback total se
estourar)** — o disparo real (P-3) fara allows() -> claim() -> envia.

**Opt-out por palavra:** no pipeline reativo, mensagem individual cujo texto normalizado
(case/acento-insensivel, match EXATO — mesma normalizacao do matcher) e a palavra configurada
revoga o opt-in + registra revoke/palavra. NAO responde nada; a mensagem SEGUE o pipeline
(pode casar regra/fluxo — provado por teste); sem opt-in = no-op sem log falso; grupo ignorado.

**UI:** card "Proativas" em /configuracoes — switch proprio com modal ao LIGAR (explica o risco
de mensagens INICIADAS; ligar so arma a jaula), tetos/janela/palavra editaveis com validacao;
AFROUXAR (teto>20, semanal>1, janela mais larga) pede confirmacao com os riscos listados;
endurecer salva direto. Painel do contato: toggle de opt-in com texto de consentimento (toda
mudanca registra grant/revoke manual) + badge "opt-in" na lista.

**Gate estendido:** switch/consentimentos/contadores por conta — ligar a A nao liga a B; claim
da A nao consome da B; palavra na B revoga so o contato da B.

**P-2 (proxima):** campanhas draft -> preview (lista EXATA de destinatarios) -> aprovar (gate
humano) + scheduler com jitter. **P-3:** disparo real pelo Sender em modo `proactive` com
allows() + claim atomico + R2 proprio.

---

## SEQUENCIA NOTURNA 2026-07-02 — P-2, P-3 e M-1 ENTREGUES

**ESCADA DE INTELIGENCIA N0-N10 COMPLETA PARA USO PESSOAL.** Detalhes e checklist de
manha em `docs/relatorios/2026-07-02-resumo-noite.md` (+ um relatorio por fatia).
- P-2 (`e1c765f`): campanhas draft->preview->aprovar + agenda com jitter (sem disparo).
- P-3 (`cf361c7`): disparo real — tick que so enfileira + job com claim atomico
  (target e tetos) + Sender modo proactive com R2 no POST + estados running/done/
  paused + opt-out no meio. TUDO OFF aguardando o gate do Fabio (1o disparo real
  controlado). Scheduler: unit de REFERENCIA em deploy/systemd (instalar de manha).
- M-1: /painel — leitura pura dos logs com cache 60s, barras CSS, periodos SP.
- Fatia futura P-4: reativacao por tempo via Kanban (TempoEstourou) + opcao de
  "dia util" na agenda.
- Proximos arcos: MT-1..MT-3 quando a conta 2 for real.

---

## V-1 — VARIAVEIS — ENTREGUE (2026-07-02)

Extensao da escada (dimensao "configuracao", nao um degrau novo): `/variaveis`.
- `{saudacao}` virou variavel de SISTEMA (`variables.is_system`) com default
  IDENTICO ao match() historico (05-11h/12-17h/resto, fuso SP) — provado por teste
  de bordas exatas e pela suite anterior intocada. Edita textos/faixas; NUNCA
  renomeia/exclui/desativa (writer E UI recusam).
- Custom por conta: `static` | `horario` (faixas HH:MM, podem cruzar meia-noite,
  sobreposicao = AVISO e a primeira vence) | `dia_semana` (dias parciais) —
  `valor_padrao` OBRIGATORIO nos condicionais. Slug `[a-z0-9_]{1,40}` unico por
  conta; reservados (nome/saudacao/data/hora/senha, fold de acento/caixa) bloqueados.
- Renderizador UNICO (`RuleResponder::render`, caminho do c620418): regras, nos de
  fluxo, IA-base, pendencia editada, campanhas, testadores e previews. Cache
  `variaveis:{account}` invalidado por observer em QUALQUER escrita. Desconhecida/
  inativa sai INTACTA (comportamento historico). Resolucao SO no envio — payload
  do modelo de IA leva o placeholder CRU (teste explicito).
- GUARDA ANTI-BYPASS DO S5 (a mais importante): valor de variavel JAMAIS contem
  `{senha:...}`/ref de segredo (writer + UI); sem variavel dentro de variavel
  (um nivel, sem recursao).
- Writers/telas (RuleWriter, KnowledgeWriter, fluxo, campanha, pendencia) AVISAM
  referencia `{x}` desconhecida — nunca bloqueiam (a licao do bug da saudacao crua).
- `VariableWriter` = caminho oficial unico; `VariableProvisioner` idempotente
  (migration p/ contas existentes + hook `Account::created`).
- Testes: 464 verdes (443 anteriores intocados + 20 VariaveisTest + 1 extensao do
  gate TenantIsolationTest com variaveis homonimas espelhadas).

---

## P-4 — RODAPE DE SAIDA OBRIGATORIO — ENTREGUE (2026-07-02)

Toda proativa SEMPRE sai com a instrucao de saida no fim (conformidade LGPD:
caminho de revogacao explicito; anti-ban: quem sabe sair manda a palavra, quem
nao sabe denuncia — e denuncia derruba numero).
- `{palavra_sair}` = nativa de SISTEMA (nome reservado contra custom): resolve
  pro valor ATUAL de `proactive_optout_word` NO ENVIO (lookup lazy, sem cache
  de proposito — trocar a palavra muda o rodape ate de campanha JA aprovada).
  Listada em /variaveis no bloco de nativas com o valor atual.
- `settings.proactive_optout_footer` (rodape PADRAO da conta, seed com a
  variavel; editavel no card Proativas de /configuracoes com preview renderizado)
  + `proactive_campaigns.optout_footer` (por campanha, PRE-PREENCHIDO com o
  padrao; congela como TEMPLATE no snapshot). Backfill: campanhas existentes
  ganharam o default (historico coerente).
- `OptoutFooterGuard` = validacao UNICA (save da campanha, card de config e
  RE-EXECUTADA na aprovacao): vazio = erro; sem {palavra_sair} e sem a palavra
  literal = erro; literal presente = AVISO recomendando a variavel; {senha:} =
  erro. Fallback no envio: campanha com coluna vazia usa o padrao da conta —
  NENHUMA proativa sai sem rodape.
- Envio (P-3): texto final = mensagem + linha em branco + rodape, renderizados
  JUNTOS pelo renderizador unico; a jaula (contem_senha) e o R2 avaliam o
  CONJUNTO. Preview da campanha mostra o texto COMPLETO — aprova-se exatamente
  o que sai. Robo reativo intocado (teste explicito).
- Testes: 476 verdes (11 novos ProactiveFooterTest + 1 extensao do gate
  TenantIsolationTest com palavra/rodape por conta). Ajuste disclosed: 2 asserts
  de texto exato do disparo proativo ganharam o rodape e o helper do
  CampanhasTest espelha o backfill — e a mudanca de comportamento DA fatia.

**Horizonte (P-5, quando o Fabio pedir):** pulo de fim de semana na agenda e
reativacao por tempo via Kanban (TempoEstourou).


---

## MT-1 — ENTREGUE (2026-07-02) — aguardando gate pro MT-2

Multi-usuario real: `account_user` (role owner|operador, D3), contexto web vem do
VINCULO do usuario re-validado POR REQUEST (sessao forjada resetada), fallback
fase-1 DESLIGADO em producao (config default false; suite legada mantem a
semantica via phpunit.xml), 403 claro pra logado sem vinculo (logout acessivel),
seletor de conta ativa (so com 2+), `msg:user:create` com senha via prompt oculto.
Backfill: Fabio = owner da conta 1 (verificado). 494 verdes (8 novos + gate
estendido com usuarios espelhados via HTTP). Smoke: webhook vivo intocado.
Detalhes: docs/relatorios/2026-07-02-mt1.md. **MT-2 so apos o gate do Fabio.**


---

## MT-2 — ENTREGUE (2026-07-02) — webhook vivo migrado; MT-3 AGUARDA a conta 2

Canal/instancia por conta: ChannelProvisioner (instancia unica, token,
credenciais cifradas; nunca toca webhook vivo divergente), env -> canal 1 via
msg:channel:sync-env (idempotente), telas/gate/comandos no canal DA CONTA.
Webhook da conta 1 MIGRADO pra rota por token com gate do Fabio + validacao
por mensagem organica real; secret global REMOVIDO em commit separado
reversivel (bdff7e6). Bug real da Evolution v2.3.7 corrigido no caminho
(headers como objeto json). 502 verdes; gate de isolamento estendido.
Detalhes: docs/relatorios/2026-07-02-mt2.md.
**MT-3 (onboarding da conta 2) NAO executa ate a conta 2 ser real (ordem do
Fabio). Proximo da ordem CH-D1 depois disso: CH-2 (Cloud API reativo-only) —
e o MATCH-1 ja esta autorizado a rodar apos o MT-2 (prompt recebido).**


---

## MATCH-1 — ENTREGUE (2026-07-02) — matching inteligente sem IA

A classe do bug do "Que horas são?" morreu. Regra de normalizacao OFICIAL
(`App\Whatsapp\TextNormalizer::normalize`, o normalizador UNICO — proibido
normalizar diferente em pontos diferentes): NFKC (best-effort ext-intl) ->
invisiveis (nbsp/zero-width/FE0F) -> caixa baixa -> fold de acentos ->
REMOCAO de pontuacao/simbolos/emoji (bordas E meio: "wi-fi"="wifi",
"horas?!"="horas", "1."="1️⃣"="1") -> colapso de espacos + trim.
- Aplicado nos DOIS lados em: RuleMatcher (exact/starts_with/contains com a
  mesma fronteira de palavra; fuzzy sobre normalizado), gatilhos de entrada de
  fluxo, OPCOES de menu, sair/cancelar e palavra de opt-out (P-1 refatorada
  pro mesmo normalizador). REGEX intocada (texto cru; tooltip documenta).
- S5: "estrito" = EXATO sobre o normalizado (frase inteira); contains/
  tolerante com senha segue bloqueado (guarda inalterada).
- Perf: `normalized_text` persistida (rule_triggers indexed + flow_triggers),
  observer em TODA escrita + backfill idempotente na migration; mensagem
  normalizada 1x por processamento.
- Auditoria de colisoes pos-normalizacao (conta 1): ZERO colisoes regra x
  regra e fluxo x regra ("Horas ?" da regra 3 normalizou pra "horas" sem
  colidir com ninguem). Detector e testador operam normalizados.
- Testador: mostra as formas normalizadas dos dois lados ("casou via") e AVISA
  sessao de fluxo ativa (o fluxo intercepta antes das regras).
- Log de SEM-MATCH: `unmatched_messages` (silencio ELEGIVEL: aprovado, nao
  grupo, nao opt-out; gravado no pipeline quando IA nao elegivel e nos 6+1
  pontos de silencio da IA), retencao 30d (`unmatched:prune` 03:10), bloco
  "Sem resposta" no /painel (contagem + frequencia) com "VIRAR REGRA" pelo
  RuleWriter (todas as guardas; gatilho Contem tolerante por default; item
  some ao promover).
- 502 -> 518 verdes, ZERO mudanca de expectativa em teste antigo (nenhum
  dependia de pontuacao no casamento). Gate de isolamento estendido
  (sem-match por conta). Proximo da ordem: CH-2.


---

## PRODUCAO -> VPS (EM MIGRACAO) — DEPLOY-0 feito no DTI (2026-07-02)

Preparacao da mudanca pro VPS Hostinger, SEM tocar o robo vivo daqui:
- **Auditoria de segredos (pre-push): LIMPA.** `.env` real nunca commitado;
  `.env.example` com chaves vazias em TODO o historico; varredura de padroes no
  historico inteiro e working tree: zero; systemd/compose so referenciam env
  (docker/evolution/.env ignorado). Unica ocorrencia: SECRETS_KEY FAKE de teste
  no phpunit.xml (dummy proposital, nao e segredo real).
- **GitHub privado**: remote `github.com/developedbyfabio/msgautomation` (SSH ja
  autenticado no servidor). Push SO apos confirmacao de repo PRIVADO no chat.
- **Pacote de migracao** em `/root/msgautomation-migracao/` (FORA do git):
  dump completo gz (15M, 48 tabelas, single-transaction), env-inventario.txt
  (63 chaves SEM valores, [SEGREDO] anotado), migracao-checklist.md (cutover em
  9 passos com o AVISO do robo-em-dobro: nunca duas Evolution conectadas),
  versoes.txt (PHP 8.5.7, MySQL 8.0.46, Redis 7.4.9, Node 22.22.3,
  evolution-api v2.3.7).
- Segredos que viajam por canal seguro (scp/terminal do VPS, nunca git):
  APP_KEY e SECRETS_KEY (IDENTICOS, decifram banco/cofre), GEMINI_API_KEY,
  EVOLUTION_API_KEY, WEBHOOK_SECRET (legado), DB_PASSWORD, docker/evolution/.env.
- O provisionamento do VPS e OUTRO prompt, rodado la, seguindo o checklist.


---

## PRODUCAO = VPS HOSTINGER — CUTOVER CONCLUIDO (DEPLOY-1, 2026-07-02)

**A producao agora e o VPS Hostinger** (srv1780620.hstgr.cloud, Campinas-SP).
O servidor DTI esta REBAIXADO A DEV. Cutover executado com a regra de ouro
respeitada (zero janelas com duas Evolution conectadas): congelamento no DTI
(kill switch OFF + services parados) -> dump final incremental (+271 msgs do
intervalo) restaurado no VPS -> logout da instancia no DTI -> QR escaneado na
Evolution do VPS (state=open 21:09 UTC) -> validacao real fim a fim
("Que horas sao?" respondida em ~10s com hora certa de SP + card no Kanban;
fluxo Menu completo com sessao completed). Estado dos switches: robo ON,
IA OFF, proativas OFF (proativas vieram ON no dump inicial e foram desligadas
pelo caminho oficial).

Infra nova (detalhes nos relatorios DEPLOY-1 F1/F2/F3 em docs/relatorios/):
- App em /srv/www/msgautomation, units systemd (serve 8080 / worker / scheduler).
- Evolution PROPRIA (stack msgautomation_evo, 127.0.0.1:8090) — isolada da
  Evolution do Nextgest que ja morava no VPS (8088). Redis do app em 6380.
- Webhook por token (MT-2) validado; suite 530/530 verde no VPS.
- Tunel Cloudflare: wa.nextgest.com.br (SO /webhook/*) e painel.nextgest.com.br
  (Cloudflare Access com One-time PIN). Nenhuma porta do app aberta na internet.

**CHECKLIST PRO FABIO APLICAR NO DTI (agora dev):**
1. Kill switch do robo: OFF permanente (ja ficou OFF no congelamento — conferir).
2. Evolution do DTI: instancia desconectada PERMANENTE (logout feito no cutover;
   nao reescanear QR la — reescanear derrubaria a sessao da producao).
3. Services msgautomation-{serve,worker,scheduler}: `systemctl disable` (alem de
   parados) pra nao voltarem num reboot.
4. Banco de la: pode ficar como massa de dev (dados reais ate 2026-07-02 21:05).
5. Dump final (dump-final-2105.sql.gz) guardado por 30 dias como fallback de
   rollback (copias: DTI e VPS em /root/msgautomation-migracao/).
6. TENANCY_SINGLE_ACCOUNT_FALLBACK e APP_ENV de la: ajustar quando for usar como
   dev (sem pressa; nada roda la ate religar na mao).

**Rollback (enquanto o dump de 30 dias viver):** logout da Evolution do VPS ->
QR de volta no DTI -> religar services de la. Dados novos do intervalo ficam no
VPS pra avaliacao.

**Proximo passo (gate): DEPLOY-1 F4 = CH-2 Parte B** — Cloud API oficial com
numero de teste, agora SEM firewall corporativo no caminho (graph.facebook.com
alcancavel do VPS, ~3.5ms).
