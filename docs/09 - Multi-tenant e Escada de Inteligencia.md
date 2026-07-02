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
| N8 | Proativas com gate | DESENHO (abaixo) | Sender/freios/filas | campanhas, scheduler, freios proprios |
| N9 | Segmentacao/tags | DESENHO (abaixo) | escopo de contatos | tags manuais+automaticas |
| N10 | Metricas | DESENHO (abaixo) | logs existentes | /painel agregado |

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
| 6 | **T-1** Tags | tags + pivot + chips na UI + acao "aplicar tag" nas board_rules + escopo por tag em regras/fluxos | K-1 | Baixo | Criar 2-3 tags reais e uma regra escopada |
| 7 | **P-1** Proativas: freios+opt-in | Bloco proativo nas settings (tudo OFF), `proactive_opt_in` no contato + registro de consentimento + opt-out por regra de sistema | MT-0 | Medio | Aprovar defaults dos tetos |
| 8 | **P-2** Campanhas com gate | campaigns/targets draft→preview→aprovar; scheduler (unit novo) + fila `proactive` + `SendProactive` (modo proactive no Sender) | P-1, K-1, T-1 | **Alto (ban)** | Aprovar a PRIMEIRA campanha com 2-3 contatos de teste (numeros do Fabio) |
| 9 | **P-3** Reativacao via Kanban | TempoEstourou + campanha continua ("sumiu X dias na coluna Y → Z") com os mesmos gates | P-2, K-2 | Alto (ban) | Acompanhar 1 ciclo real com teto minusculo |
| 10 | **M-1** Metricas | /painel com agregados dos logs + funil do Kanban | K-1 (funil; resto independe) | Baixo | Validar numeros contra a realidade |
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
