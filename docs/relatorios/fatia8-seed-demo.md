# Fatia 8 — Seed de demo (popular uma conta com exemplos em todas as abas) — 2026-07-04

**Status: ENTREGUE** (a tentativa anterior, registrada em `fatia8-STOP-pre-requisito.md`,
abortou por falta da Fatia 7 — agora a 7 existe e foi usada). Baseline 734 → **744 verdes**
(+10, 2899 assertions). **Zero migration.** Rodado **uma vez** na conta do Fabio (resumo abaixo).

## Git no início
HEAD `7613676` (fatia 7), working tree limpo fora os 2 relatórios untracked de sessões
anteriores (`diagnostico-modo-automatico.md`, `fatia8-STOP-pre-requisito.md` — intocados).

## Comando
`php artisan msg:seed-demo {--account=<id>} {--email=<email>}` — `app/Console/Commands/SeedDemo.php`
(prefixo `msg:` segue a convenção dos comandos existentes).
- **Alvo explícito obrigatório:** sem opção → erro e `FAILURE`, nada criado. `--account`
  inexistente → aborta. `--email`: resolve o usuário e **exige exatamente 1 conta** no pivot
  (0 ou 2+ → aborta pedindo `--account`). Nunca há default e nunca itera contas.
- **Escopo:** todo o trabalho roda dentro de `AccountContext::runAs(contaAlvo)` — as checagens
  de existência usam o scope global dos models (todos os domínios têm `BelongsToAccount`) e os
  creates ainda passam `account_id` explícito. Sem `runAs`, o fallback fase-1 poderia mirar a
  conta mais antiga — o vazamento que o comando não pode ter.

## Como cada domínio é semeado (caminho oficial reusado + marcador de idempotência)
| Domínio | Caminho | Idempotência / guarda |
|---|---|---|
| Variáveis | `VariableProvisioner::ensureSystemVariables` ({saudacao}) + `VariableWriter::save` ({empresa} static) | provisioner já é idempotente; `{empresa}` só se não existir |
| Cofre | `SecretVault::put` | **só se o nome não está em `names()`** — `put` é updateOrCreate e NUNCA pode sobrescrever segredo real |
| Tags | `Tag::firstOrCreate` (cliente/lead/vip, cores da paleta) | chave (account, name) |
| Contatos | `Contact::firstOrCreate`, `saved=true` + tags via pivot `origin=manual` | chave (account, remote_jid); vínculo de tag checa existência |
| Conversas | `IncomingMessage::create` (in/out, inertes) | `evolution_message_id` com prefixo **`DEMO-`**; `raw_payload={'seed':'demo'}` |
| Regras | `RuleWriter::save` (oficial, mesmo das UIs) | pula se a conta já tem regra com a MESMA resposta **ou** gatilho com o mesmo valor (não cria concorrente de regra real) |
| Conhecimentos | `Knowledge::firstOrCreate` | chave (account, title) com prefixo "Exemplo — " |
| Fluxos | **`InstantiateFlowTemplate::handle`** sobre **`FlowTemplateCatalog::all()`** (nomes REAIS da Fatia 7) | pula se já existe fluxo com o nome do template na conta |
| Campanha | `ProactiveCampaign::create` **status `draft`**, sem targets | pula se o nome "Exemplo — Reativacao de clientes" existe |
| Kanban | `BoardProvisioner::ensureDefaultBoard` + `Card::create` (3 contatos FAKE em colunas distintas) | card é 1 por contato — se existe, não mexe |

## Guards confirmados
- **Cofre FAKE:** `token_exemplo=TOKEN_EXEMPLO_123`, `api_key_demo=API_KEY_DEMO_abc`
  (categoria `demo`, nota "valor FAKE — pode apagar"). Teste assert nos valores.
- **Campanha só draft:** criada direto com `status='draft'` (mesmo caminho da UI, que não
  despacha job na criação); sem targets, sem `approved_*`. Teste com `Queue::fake` +
  `Http::fake`: **nenhum job pushado, nenhum HTTP enviado**.
- **JIDs FAKE documentados:** `550000000000{1..4}@s.whatsapp.net` — DDI 55 + DDD **00**
  (inexistente no Brasil): não colide com contato real e não recebe envio.
- **Isolamento:** semear A não cria nada em B (teste compara contagens de 10 domínios).
- **Aditivo:** teste dedicado planta segredo real com o MESMO nome + regra real de "oi" antes
  do seed → segredo preservado, regra sem concorrente, demais exemplos entram.
- **Não** seta `default_flow_id` nem `operation_mode` (o comando nem toca `AutoReplySetting`).

## Nomes reais da Fatia 7 usados
`App\Whatsapp\Flows\FlowTemplateCatalog` (keys `clinica`, `salao`, `comercio`) e
`App\Whatsapp\Flows\InstantiateFlowTemplate::handle($key, $accountId)` — nenhuma reimplementação.

## Testes — `tests/Feature/SeedDemoCommandTest.php` (10)
1. **`sem_alvo_aborta_sem_criar_nada`** (destaque — alvo obrigatório).
2. `conta_inexistente_aborta_sem_criar_nada`.
3. `email_resolve_conta_unica_e_ambiguidade_aborta` — 1 conta ok; 2 contas aborta; email
   desconhecido aborta.
4. `popula_todos_os_dominios_na_conta_alvo` — 3 regras (exact/contains/starts_with), 2 secrets,
   3 KBs "Exemplo — ", 3 tags, os 3 fluxos da Fatia 7, campanha draft sem targets,
   {saudacao}+{empresa}, 4 contatos FAKE saved, 6 mensagens DEMO- (in+out), 3 cards em colunas
   distintas.
5. `secrets_sao_placeholders_fake` — reveal retorna os placeholders.
6. **`rodar_duas_vezes_nao_duplica_nada`** (destaque — idempotência: contagens idênticas).
7. **`aditivo_nao_sobrescreve_dado_existente`** — segredo real e regra real preservados.
8. **`semear_a_conta_a_nao_cria_nada_em_b`** (destaque — isolamento).
9. **`seed_nao_despacha_job_nem_envia_nada`** (destaque — sem disparo; Queue+Http fake).
10. `fluxo_semeado_abre_no_editor_e_tem_handoff` — editor 5b abre; 2 handoffs com message.

## Contagem
Antes: **734 verdes / 2847 assertions** (pós-Fatia 7). Depois: **744 verdes / 2899 assertions**
(+10). Suíte inteira sequencial verde.

## Execução em produção (conta do Fabio)
Alvo identificado por leitura: conta **#1 "msgautomation"**, única conta do usuário
`fabio9384@gmail.com` (platform admin). `php artisan msg:seed-demo --account=1` (foreground, 1x):

```
variaveis:      1 criado ({empresa}), 1 já existia ({saudacao})
cofre:          2 criados (token_exemplo, api_key_demo — FAKE)
tags:           3 criadas (cliente, lead, vip)
contatos:       4 criados (jids FAKE 550000000000X, saved)
conversas:      6 criadas (threads inertes DEMO-, in+out)
regras:         2 criadas (saudação oi/ola, preço), 1 PULADA — guard anti-conflito:
                a regra real #5 do Fabio já tem gatilho "Horário" (collation do MySQL
                casa sem acento/caixa) → o seed não criou concorrente. Comportamento desejado.
conhecimentos:  3 criados ("Exemplo — ...")
fluxos:         3 criados (Clínica / consultório, Salão de beleza / barbearia,
                Comércio / estabelecimento) — os pré-existentes "Atendimento (exemplo)"
                e "Novo fluxo" intocados
campanhas:      1 criada (draft, público = tag lead)
kanban:         3 cards (em_atendimento, novo, resolvido)
```

**Atenção (comportamento herdado da Fatia 7, por design):** os 3 fluxos instanciados nascem
**ligados** (`enabled=true`) com gatilhos `menu`/`consulta`/`agendar`/`comprar` — mensagens
reais contendo essas palavras podem abrir os menus se os gates de auto-reply passarem. Se não
for a hora de testá-los, desligar na aba Fluxos (1 clique cada).

## Commit
Local, sem push (Fabio empurra). Hash reportado na resposta.
