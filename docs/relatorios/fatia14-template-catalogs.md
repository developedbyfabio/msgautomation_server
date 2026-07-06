# Fatia 14 — Catálogos de templates: Regras, Conhecimentos, Campanhas e Variáveis — 2026-07-06

Git no início: HEAD `8e2c7a3` (fatia 13), working tree limpo exceto os dois relatórios untracked
pré-existentes (fora do commit). Baseline: **782 verdes / 3087 assertions**.

---

## Mapeamento (lido antes de escrever)

- **Molde (Fatia 7):** `FlowTemplateCatalog` (summaries/all/get com `InvalidArgumentException` em
  key desconhecida) + serviço `Instantiate*` + `uniqueName` (sufixo " (2)", " (3)"... por conta).
  Espelhado nos 4 domínios.
- **Caminhos oficiais de escrita reusados** (os mesmos do seed da Fatia 8, corrigido um ponto: o
  seed gravava KB com `firstOrCreate` direto, mas o caminho oficial do CRUD é o `KnowledgeWriter` —
  os templates usam o writer):
  | domínio | caminho oficial |
  |---|---|
  | Regras | `RuleWriter::save` (guardas S5/regex/escopo; `normalized_text` no saving do trigger) |
  | Conhecimentos | `KnowledgeWriter::save` (guarda de `{senha:}`) |
  | Campanhas | `ProactiveCampaign::create` status `draft` (mesmo shape do CRUD/seed/Fatia 13) |
  | Variáveis | `VariableWriter::save` (nome válido/não-reservado/único, sem segredo/recursão) |
- **`AutoReplyRule` TEM `enabled` individual** (persistido pelo writer, `$dados['enabled']`) — o
  design item 3 aplica sem adaptação: **toda regra de template nasce `enabled=false`**.
- **Anti-conflito de trigger (comportamento do caminho oficial, registrado):** o `RuleWriter` **não
  bloqueia** colisão de gatilho com regra existente — grava normalmente (o guard do seed era do
  próprio seed; a UI tem o `RuleConflictDetector` que só avisa). Consequência para templates:
  instanciar template cujo trigger colide **cria a regra mesmo assim, OFF** — zero disputa de
  matching até o usuário habilitar conscientemente; a original fica intacta. Provado por teste.
- **Regra não tem campo nome** — não há o que sufixar; instanciar 2x cria duas regras OFF (ambas
  visíveis na lista). Comportamento oficial, registrado e provado.
- **Estrutura real de variável:** `type` ∈ `static|horario|dia_semana`; `static` = `config['valor']`
  (texto simples). Os 3 templates são `static`. **Nome duplicado: o writer REJEITA** ("Ja existe
  variavel com esse nome") — decisão registrada: **não sufixar** (o nome é a identidade de
  referência `{empresa}`; uma `{empresa_2}` não seria a variável que as mensagens referenciam) —
  a UI mostra o motivo no toast e **nada é sobrescrito**.
- **Placeholders `[colchetes]`** de propósito: não disparam o aviso de `{refs}` desconhecidas do
  writer nem as guardas de recursão/segredo (que olham `{...}`).

## Catálogos criados (4 domínios, declarativos, sem framework genérico)

- `App\Whatsapp\AutoReply\RuleTemplateCatalog` + `InstantiateRuleTemplate` — **5 regras**:
  Boas-vindas (oi/olá/bom dia/boa tarde/boa noite, exact), Horário (contains horário/que horas/
  funcionamento — "horário" cobre "horários" por substring pós-normalização), Endereço, Preços,
  Agradecimento (única sem placeholder — pronta). Todas nascem **OFF**, escopo global, cooldown
  global.
- `App\Ai\KnowledgeTemplateCatalog` + `InstantiateKnowledgeTemplate` — **4 conhecimentos**:
  Horário de funcionamento, Endereço e como chegar, Formas de pagamento, Política de
  cancelamento/reagendamento. Nascem `active=true` + `sensitivity='low'` (conteúdo passivo — IA é
  OFF por padrão e nada dispara sozinho); **título sufixado** em colisão.
- `App\Whatsapp\Proactive\CampaignTemplateCatalog` + `InstantiateCampaignTemplate` — **3
  campanhas**: Promoção, Comunicado, Reativação. Nascem **`draft`** com público **vazio**
  (`tags:[]` — o form exige definir antes de salvar/aprovar), `start_at`/aprovação nulos, rodapé
  opt-out **da conta** (mesmo pré-preenchimento do `Campanhas::novo`; fallback do catálogo com
  `{palavra_sair}` se a conta não tiver). Nome sufixado. **Nunca** job/target/agenda.
- `App\Variables\VariableTemplateCatalog` + `InstantiateVariableTemplate` — **3 variáveis**
  `static`: `{empresa}`, `{atendente}`, `{horario_funcionamento}`, com valores-placeholder.

## UI (onde cada botão entrou)

Seção "**Comecar com um modelo**" (mesmo markup da Fatia 7: grid de cards nome+descrição + botão
"Usar modelo") ao final da listagem de cada aba — `regras.blade.php`, `conhecimento.blade.php`,
`campanhas.blade.php`, `variaveis.blade.php`. Instanciar **abre o item no form de edição** da
própria aba (padrão "abre para edição" da Fatia 7) com toast orientando a trocar os `[colchetes]`.
Key inválida → toast de erro, nada criado. `summaries()` só é consultado com o form fechado.

## Testes

- `RuleTemplateTest` (6): integridade dos 5 (triggers/respostas persistidos, `normalized_text`
  vivo, **todas OFF**, conta certa); UI cria OFF e abre o form; **colisão de trigger** com regra
  ligada do usuário → template criado OFF e original intacta (comportamento oficial provado);
  instanciar 2x = duas regras OFF (sem nome pra sufixar — registrado); key inválida sem efeito
  (toast + exceção no serviço); isolamento A/B.
- `KnowledgeTemplateTest` (5): integridade dos 4 (título/conteúdo/low/active); **título sufixa**
  " (2)"; UI abre form; key inválida; isolamento.
- `CampaignTemplateTest` (6): integridade dos 3 (draft, mensagem do template, rodapé presente,
  start_at/aprovação nulos, **0 targets**); **`Queue::fake` + `assertNothingPushed`**; nome sufixa;
  UI abre form; key inválida; isolamento.
- `VariableTemplateTest` (5): integridade dos 3 **resolvíveis pelo renderizador oficial**
  (`RuleResponder::render('{empresa}')` devolve o valor do template); **duplicata rejeitada sem
  sobrescrever** o valor real do usuário; UI abre form; key inválida; isolamento.

## Contagem de testes

| | testes | assertions |
|---|---|---|
| Antes | 782 | 3087 |
| Depois | **804** | **3235** |

Suíte inteira **sequencial**, tudo verde — **zero teste existente alterado**.

## Confirmações explícitas

- **Seed da Fatia 8 intocado** (`SeedDemo.php` sem diff). **Pipeline, dispatch de campanhas,
  resolver de variáveis, motores de regra/fluxo: sem diff** — só catálogos + instanciação + UI.
- Diff de produção: 8 classes novas + 4 componentes Livewire (+`usarTemplate` e `templates` no
  render, tudo aditivo) + 4 blades (+seção de modelos). Zero migration.
- Isolamento por conta em toda instanciação (accountId explícito nos serviços; testes A/B).

## Commit
Local, sem push (Fabio empurra). Hash reportado na resposta.
