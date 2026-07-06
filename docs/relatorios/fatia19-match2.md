# Fatia 19 — MATCH-2: matching tolerante a erro humano, com desempenho como requisito — 2026-07-06

Git no início: HEAD `916f593` (fatia 18), working tree limpo exceto os dois relatórios untracked
pré-existentes (fora do commit). Baseline: **849 verdes / 3371 assertions**.

---

## Pipeline final

**Camada BASE** (`TextNormalizer::normalize` — todos os modos): o que já existia (NFKC,
invisíveis, caixa, fold de acento, pontuação/emoji fora, colapso de espaços) **intocado** + um
único passo novo (5b): **squeeze de repetição expressiva** — runs de **3+** letras iguais → 1
("oiii"→"oi", "simmm"→"sim"). Runs de 2 NÃO colapsam na base (contrato estrito preservado —
"oii" ≠ "oi" no exato, provado).

**Camada FONÉTICA** (`TextNormalizer::phonetic` — SÓ o caminho tolerante), tabela final:

| # | colapso | exemplo |
|---|---|---|
| 0 | `ç`→`s` **antes** da base | "preço"→"preso" (desvio registrado: o `Str::ascii` da base dobra ç→c e perderia o som /s/ — por isso o pré-passo) |
| 1 | `ch`→`x`, `sh`→`x`, `ph`→`f` | "chave"≈"xave" |
| 2 | `h` inicial de palavra removido | "horário"≈"orario" |
| 3 | `k`→`c`, `w`→`v`, `y`→`i`, `z`→`s` | "meza"≈"mesa" |
| 4 | `c` antes de e/i → `s` (após k→c) | "cedo"≈"sedo" |
| 5 | letras duplicadas → 1 | "presso"→"preso"; "oii"→"oi" (só no tolerante) |

**Excluídos deliberadamente** (regra de ouro "na dúvida, fora"): **`qu`→`q`** — encurtaria "que"
pra 2 letras e **matava a transposição "qeu"≈"que"** (descoberto por teste; e "qero"≈"quero" já
casa por distância 1 sem ele); **nh/lh** ("senha" não vira "sena" — conservadorismo); **gu**.
Ordem pensada pra idempotência (nenhum passo cria padrão que um anterior transformaria) —
idempotência provada por propriedade sobre amostra.

## Distância: estratégia escolhida

**`levenshtein()` nativo (C) + verificação O(n) de transposição única**: quando o nativo devolve
2 com tamanhos iguais, `isSingleTransposition` (scan sem alocação) detecta a troca de vizinhos e
conta **1** ("pxi"→"pix", "qeu"→"que"). OSA própria em PHP não foi adotada: o benchmark mostrou o
matcher inteiro a +3,1% do baseline com o nativo — não havia o que justificar uma DP em PHP
(ordens de magnitude mais lenta por chamada que a função em C).

**Reconciliação registrada (curto <4):** a regra literal "palavra curta segue exata" conflitava
com a linha normativa da matriz da própria fatia ("pxi"→"pix" casa, len 3). Resolução: token
curto **não tolera edição** (inserção/deleção/substituição — "pai" ≠ "pix" segue fora), mas aceita
**exclusivamente a transposição adjacente única** (todas as letras presentes, só trocadas —
typo clássico de digitação, risco baixo). Vale em todos os níveis.

## Mapa nível→limiar (mantido, não reinventado)

`folga = min(teto, len_do_token ÷ divisor)` sobre o token FONÉTICO do gatilho:

| nível | divisor | teto | len 4–5 | len 6–7 | len 8–11 | len 12+ |
|---|---|---|---|---|---|---|
| baixa | 6 | 1 | 0 | 1 | 1 | 1 |
| media | 4 | 2 | 1 | 1 | 2 | 2 |
| alta  | 3 | 2 | 1 | 2 | 2 | 2 |

(len < 4: exato ou transposição, acima. Early-exit por diferença de comprimento > folga mantido.)

## Forma fonética PERSISTIDA (decisão + número)

Persistida em coluna aditiva **`normalized_phonetic`** em `rule_triggers` **e** `flow_triggers`
(migration `2026_07_06_000003`), preenchida pelo `saving` dos dois models (que também mantém o
`normalized_text` — confirmado) e pelo backfill. Justificativa: computar a fonética do gatilho no
hot path seria O(gatilhos tolerantes) cadeias de preg por mensagem; persistida, o custo marginal
do MATCH-2 medido ficou em **+0,26 ms/mensagem (+3,1%)** — a mensagem é normalizada 1x (base) e
foneticizada **no máximo 1x por avaliação, lazy** (só se aparecer gatilho tolerante), a partir do
texto **cru** (o normText já perdeu o ç no fold ascii).

## Backfill (o risco central) — executado em produção

Comando **`msg:renormalize-triggers`** (idempotente: recomputa a forma esperada e só faz UPDATE
onde difere; campos DERIVADOS apenas; cobre as duas tabelas, todas as contas, em chunks):

- 1ª execução: **22 rule_triggers + 10 flow_triggers re-normalizados**;
- 2ª execução: **0 mudanças (22+10 "já corretos")** — idempotência provada em produção;
- pós-ajuste da tabela fonética (remoção do qu→q): 1 gatilho reajustado, novamente 0 na re-execução;
- prova por leitura: **0 stale** (normalized_text ≠ pipeline nova), **0 sem phonetic**; amostra:
  `'Senha do wifi' → norm='senha do wifi' phon='senha do vifi'` (w→v simétrico nos dois lados).
- **Gerações indistinguíveis** provadas por teste: gatilho com colunas regredidas à pipeline velha
  + backfill == gatilho criado novo (colunas e matching idênticos).

## Benchmark (carga: 200 gatilhos mistos — 1/3 exact, 2/3 contains, 1/3 tolerante média — em 100 regras × 1.000 mensagens variadas, seed fixa; `tests/Benchmark/MatchBench.php`, fora da suíte default, rodar explícito)

| | média/msg | p95/msg |
|---|---|---|
| ANTES (HEAD `916f593`) | **8,412 ms** | 10,325 ms |
| DEPOIS (MATCH-2) | **8,672 ms** | 9,518 ms |
| variação | **+3,1%** | −7,8% |

Dentro do orçamento (≤ +25%; poucos ms/mensagem — o custo é dominado pela query+hidratação das
100 regras, idêntico nos dois lados; o delta do matching em si é sub-milissegundo).

## Parte D — layout do dropdown de intensidade

Antes: o select ("média") vivia DENTRO da coluna do texto, abaixo do input — quebrava desalinhado.
Depois: linha única de controles `[tipo][precisão][intensidade][texto][×]` (`flex flex-wrap
sm:flex-nowrap items-center`; intensidade `w-24` com o mesmo `py-2 text-sm` dos vizinhos); o hint
âmbar ficou **abaixo da linha inteira**, largura total. **Breakpoint registrado: `sm`** — abaixo
dele quebra intencionalmente (flex-wrap), alinhado. Só apresentação.

## Testes

- **Contrato (SEM tocar):** `RuleMatcherTest`, `MatchNormalizationTest`, `FuzzyMatchTest`,
  `RuleScopeTest`, `RuleConflictTest`, `RuleCooldownTest`, `RuleTesterTest`, `RegrasAvancadasTest`
  — **72 testes / 238 assertions verdes sem nenhuma alteração**. Ajustes deliberados: **zero**.
- **`Match2Test` (novo, 13 casos):** transposição ("pxi"→"pix", "qeu horas"→"que horas"); falta de
  letra ("snha", "endereo"); fonéticos ("presso"/"presu"≈"preço", "orario"≈"horário",
  "meza"≈"mesa", "xave"≈"chave"); repetição expressiva na BASE (exato casa "oiii"/"simmm"; "oii"
  não casa no exato mas casa no tolerante via dedup fonético); anti-falso-positivo ("pai"≠"pix";
  "ola" estrito ≠ "cola"; 5 pares reais travados no nível média: senha≠sonho, preço≠prato,
  horário≠armário, boleto≠bolo, entrega≠entrada); intensidades distintas ("presu"×"preço": baixa
  não, média sim — mapa observável); **idempotência** das duas camadas por propriedade;
  **gerações de backfill indistinguíveis**; gatilho de FLUXO também fonético (e não casa
  "armario"); **ranking inalterado** (dois tolerantes competindo: vence o mais longo);
  smoke do layout (intensidade na linha, hint abaixo).
- **`tests/Benchmark/MatchBench.php`** (novo, fora da suíte default): reprodutível, seed fixa.

## Contagem de testes

| | testes | assertions |
|---|---|---|
| Antes | 849 | 3371 |
| Depois | **862** | **3430** |

Suíte inteira **sequencial**, tudo verde.

## Confirmações explícitas

- **Suíte de matching existente sem diff** (contrato); **IA sem diff** (`ClassifyWithAi`/fila/
  promoção intocados); ranking/precedência/isolamento inalterados; **zero chamada de API** no
  matching (100% determinístico e local); sem dependência composer nova.
- Migration única e aditiva (normalized_phonetic ×2); backfill = UPDATE de campo derivado,
  idempotente, rodado em produção com prova por leitura.
- **`queue:restart` executado após o commit (FUNCIONAL: o matcher roda 100% em job)** — worker
  novo confirmado (pid/horário na resposta).

## Commit
Local, sem push (Fabio empurra). Hash reportado na resposta.
