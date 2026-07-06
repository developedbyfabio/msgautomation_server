# Fatia 15 — Conhecimentos como variável: token `{kb:slug}` em mensagens — 2026-07-06

Git no início: HEAD `aee09cd` (fatia 14), working tree limpo exceto os dois relatórios untracked
pré-existentes (fora do commit). Baseline: **804 verdes / 3235 assertions**.

---

## Mapeamento (lido antes de escrever)

- **KB (`knowledge`):** `account_id`, `title`, `content`, `sensitivity` (low|medium|high),
  `active`, pivô `knowledge_contacts` (vazio = qualquer contato com IA; preenchido = restrito).
  **Não havia slug/campo estável** → migration aditiva necessária. Semântica real de sensitivity:
  `high` NUNCA vai ao modelo nem é respondido direto; `low/medium` entram no prompt da IA. Para
  **inserção direta em mensagem automática** (sem crivo de IA/humano) a barra adotada é a mais
  conservadora: **só `low`** é referenciável — `medium` vai no máximo ao MODELO, nunca direto ao
  contato (adaptação registrada). Além disso: **sem restrição de contatos** (mensagem de fluxo vai
  pra qualquer um; entrada restrita não pode vazar).
- **Resolver É CENTRAL:** `RuleResponder::render` — "renderizador ÚNICO (c620418): regras, nós de
  fluxo, IA-base, edição de pendência, campanhas, testadores e previews". No caminho de envio, o
  `SendAutoReply` seta o `AccountContext` **da conta do envio** (linha ~55) antes de renderizar —
  o mesmo escopo que `{empresa}`/custom usam (`variaveisDaConta()` lê o contexto). `{kb:}` entrou
  ali: **regras, fluxos e campanhas ganharam juntos.**
- **Guardas existentes de recursão/segredo:** `VariableWriter` PROÍBE `{...}` dentro de valor de
  variável ("um nível só, sem recursão") e proíbe `{senha:}` em variável. O `render()` não re-escaneia
  texto substituído. **KB pode conter `{senha:nome}` legitimamente** (caminho da IA: placeholder
  intacto até o Sender resolver no POST) — risco mapeado: injetar esse conteúdo via `{kb:}` faria o
  Sender resolver a senha depois do render, por caminho não-escopado. **Fechado**: conteúdo com
  `{senha:}` é tratado como órfão (mesmo espírito do S5).
- **Detector:** `Variable::unknownRefs(accountId, texto)` — usado por `RuleWriter` (warnings),
  `Fluxos::salvarNo` (toast) e Campanhas. Regex `\{(\w+)\}` não casa `:` → passe próprio adicionado.
- **Editor (5b):** `message` do nó é textarea com buffer `nodeMsg[id]`; já existe o padrão
  `inserirSenhaNo` (dropdown "senha" → **anexa no FIM do campo**). Espelhado.

## Migration (única, aditiva) + backfill

`2026_07_06_000001_add_slug_to_knowledge.php`: coluna `slug` (80) nullable após `title`; backfill
idempotente (preenche **só** slug NULL: slugify do título com sufixo `-2`/`-3` em colisão POR
conta); depois `unique (account_id, slug)`. `down()` remove apenas o que a migration adicionou.

**Evidência em produção** (`php artisan migrate --force`, foreground): 3 entradas existentes
(conta 1, do seed) preenchidas, 0 com slug null —
`exemplo-horario-de-funcionamento`, `exemplo-formas-de-pagamento`, `exemplo-politica-de-trocas`.
Re-rodar o backfill seria no-op (filtra `whereNull('slug')`).

**Geração na criação:** hook `creating` no model `Knowledge` (choke point único — cobre
KnowledgeWriter, seed e qualquer caminho); `uniqueSlugFor` sufixa por conta. **Imutável no
rename:** nenhum caminho de update toca o slug (provado por teste).

## Onde o `{kb:}` entrou e o alcance real

`RuleResponder::render`, passe **separado e POSTERIOR** ao principal
(`/\{kb:([a-z0-9_-]+)\}/iu`): o conteúdo inserido é **LITERAL** — `{refs}` dentro dele não
resolvem (mesma filosofia sem-recursão do VariableWriter; o preg não re-escaneia substituições).
Alcance: **fluxo, regra e campanha juntos** (renderizador único) — provado por teste nos caminhos
de fluxo e de regra via pipeline completo.

Tratamento de órfão/sensível (trecho essencial de `conteudoKb`):

```php
$kb = Knowledge::query()->referenciavel($accountId)->where('slug', $slug)->first();
if ($kb === null || app(SecretVault::class)->hasRef((string) $kb->content)) {
    Log::warning('{kb:' . $slug . '} nao resolvido (...) — substituido por vazio.', [...]);
    return ''; // token NUNCA vaza; envio NUNCA quebra
}
return (string) $kb->content;
```

`scopeReferenciavel` = `account_id` explícito + `active` + `sensitivity='low'` +
`whereDoesntHave('contacts')`. Sem contexto de conta (não acontece em caminho de envio): vazio +
warning (contrato "token nunca vaza" acima do comportamento intacto das custom — registrado).

## Editor + detector

- **Dropdown "conhecimento"** (ícone book-open) ao lado do "senha" na edição do nó, listando os
  títulos referenciáveis da conta ativa (mesma elegibilidade do resolver, incluindo o filtro de
  `{senha:}` — o dropdown nunca oferece o que não resolveria). `inserirConhecimentoNo` valida
  posse/elegibilidade server-side e **anexa no FIM do campo** (escolha registrada: mesmo padrão do
  `inserirSenhaNo`; posição de cursor exigiria JS fora do padrão do editor).
- **Detector estendido:** `Variable::unknownRefs` agora valida `{kb:...}` com a MESMA elegibilidade
  do resolver — o que não resolveria no envio vira aviso `kb:slug` (propaga automaticamente pros
  três editores que já usam o detector: regras, nós de fluxo e campanhas).

## Testes (`tests/Feature/KbVariableTest.php`, 9 casos)

1. **Slug:** gerado na criação (writer E criação direta — hook), slugify com acentos;
   **imutável no rename** (título muda, slug fica); colisão sufixa `-2` na conta; mesmo título em
   outra conta usa o slug base (unicidade por conta).
2. **Fluxo:** nó com `{kb:horarios}` → pipeline completo → mensagem enviada com o CONTEÚDO.
3. **Regra:** resposta com `{kb:endereco}` → idem (prova do renderizador central).
4. **Órfão:** slug inexistente → sai **vazio** (não o token literal — assert explícito de que
   `{kb:` não aparece), envio não quebra, `Log::warning` recebido.
5. **Sensível/restrito/com-senha:** `medium`, `low` restrito a contatos e `low` com `{senha:wifi}`
   — os três saem vazios; nada do conteúdo vaza (asserts por substring).
6. **Literal (recursão):** KB com `{empresa}` no conteúdo → mensagem sai com `{empresa}` LITERAL
   (variável ativa da conta NÃO é resolvida dentro de conteúdo injetado — sem recursão).
7. **Isolamento (crítico):** mesmo slug `horarios` nas contas A e B → envio pela conta A resolve
   `CONTEUDO-DA-A` (e nunca o da B).
8. **Detector:** `kb:nao-existe` e `kb:interno` (medium) avisam; `kb:horarios` (válido) não.
9. **Dropdown/inserção:** editor lista só o referenciável (sensível fora); inserir anexa
   `{kb:slug}` no fim do buffer; slug inelegível forjado NÃO insere.

## Contagem de testes

| | testes | assertions |
|---|---|---|
| Antes | 804 | 3235 |
| Depois | **813** | **3260** |

Suíte inteira **sequencial**, tudo verde — **zero teste existente alterado** (testes do caminho da
IA — `AiFallbackTest`, `KnowledgeModeTest` etc. — verdes sem nenhum diff).

## Confirmações explícitas

- **Caminho da IA sem diff** (`ClassifyWithAi`, `KnowledgeWriter`, scopes de candidatos — nada
  tocado; `scopeCandidatesFor` intacto). **Decisões do pipeline sem diff** (nenhuma linha em
  `ProcessIncomingWhatsappMessage`/gate/motores — a mudança é substituição de texto no render).
- Migration única e aditiva (a do slug), forward, foreground, com backfill idempotente confirmado
  por leitura em produção. Isolamento por conta em resolução, dropdown e detector (provado).
- Observação registrada (sem mexer, fora do escopo): o hint do nó handoff no editor ainda diz
  "move o card pra Em atendimento" — desde a fatia 11 o destino é "Aguardando resposta"; copy a
  atualizar num polish futuro.

## Commit
Local, sem push (Fabio empurra). Hash reportado na resposta.
