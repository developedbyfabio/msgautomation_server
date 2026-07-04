# Fatia 7 — Templates de fluxo de atendimento (catálogo + instanciar) — 2026-07-04

**Status: ENTREGUE.** Baseline 724 → **734 verdes** (+10, 2847 assertions). **Zero migration**
(catálogo em código; instanciação usa as tabelas existentes). **Motor da Fatia 5 e internas
do editor 5b intocados** — o editor só ganhou o ponto de entrada `usarTemplate` (lançar +
"redirect") e a view a seção de modelos.

## Git no início
HEAD `61f6fba` (fatia 5b). Working tree: 3 untracked — `docs/relatorios/diagnostico-modo-automatico.md`,
`docs/relatorios/fatia8-STOP-pre-requisito.md` (sessões anteriores, deixados como estavam) e um
`app/Whatsapp/Flows/FlowTemplateCatalog.php` de uma tentativa anterior interrompida da própria
fatia 7 (só o catálogo; sem serviço, UI ou teste). Foi **reescrito** nesta fatia para casar com
o copy/estrutura especificados no prompt (clínica verbatim; comércio com raiz plana; finais
convidando a mandar nova mensagem pra voltar ao menu).

## Catálogo (registry) — onde fica e como estender
`app/Whatsapp/Flows/FlowTemplateCatalog.php` (mesmo namespace do `FlowEngine`). Dados puros em
código, **não** dado de tenant. Cada blueprint: `key`, `name`, `description`, `timeout_seconds`,
`invalid_message`, `triggers` (gatilhos de entrada) e `root` — árvore declarativa de nós
`['kind' => menu|final|handoff, 'message' => ..., 'options' => [['input','label','node' => filho]]]`.
**Adicionar template novo** = criar um método privado com o blueprint e registrá-lo no array de
`all()`. Nada mais (UI, instanciação e teste de integridade pegam o novo template sozinhos).

## Serviço de instanciação — como constrói o shape
`app/Whatsapp/Flows/InstantiateFlowTemplate.php`:
1. `assertBlueprint` **falha alto antes de escrever**: raiz deve ser menu COM opções (achado da
   Fatia 4 — menu sem opção vira saudação de um tiro), gatilho presente, handoff/final terminais,
   handoff com message (invariantes que o editor 5b valida).
2. Em **transação**: cria `Flow` (`account_id` **explícito** da conta informada, `enabled=true`,
   `scope=global`) + `FlowTrigger`s (`precision=exato`), depois desce a árvore recursivamente:
   cada nó vira `FlowNode` (`parent_node_id` + `ordem` sequencial via DFS) e cada opção vira
   `FlowOption` com `next_node_id` apontando pro filho recém-criado; por fim `root_node_id`.
É **exatamente** o shape que editor e motor esperam — o mesmo que `FlowEditorHandoffTest::
test_fluxo_com_handoff_pre_existente_abre_e_edita_sem_quebrar` (5b) monta programaticamente, e o
mesmo que `Fluxos::novoFluxo`/`definirDestino` produzem clique a clique. `enabled=true` é
coerente com a validação do `toggleFluxo` (exige gatilho + raiz — ambos criados). Nome = nome do
template; colisão na conta ganha sufixo " (2)", " (3)"... (`uniqueName`, consulta com bypass
nomeado `withoutAccountScope()` + filtro explícito de conta).

## Os 3 templates
Todos: raiz **menu** com 4 opções, todo `handoff` terminal e com message contextual, todo `final`
terminando com convite a mandar nova mensagem pra voltar ao menu.
- **`clinica`** (copy verbatim do prompt): 1 Agendar consulta → `handoff`; 2 Convênios e valores →
  `final`; 3 Endereço e horário → `final`; 4 Falar com um atendente → `handoff`. Gatilhos:
  contains `menu`, `consulta`.
- **`salao`**: 1 Agendar horário → `handoff`; 2 Serviços e preços → `final`; 3 Localização e
  funcionamento → `final`; 4 Falar com atendente → `handoff`. Gatilhos: `menu`, `agendar`.
- **`comercio`**: 1 Horário de funcionamento → `final`; 2 Nossos produtos → `final`; 3 Como
  comprar / fazer pedido → `handoff`; 4 Falar com atendente → `handoff`. Gatilhos: `menu`, `comprar`.

## UI — listar e instanciar (com redirect)
Área de Fluxos (`app/Livewire/Fluxos.php` + `resources/views/livewire/fluxos.blade.php`), só no
modo **lista**: seção "Começar com um modelo" com um card por template (nome + descrição) e botão
"Usar modelo" → `usarTemplate(key)` instancia na **conta ativa** (`AccountContext`, mesma
disciplina das fatias 3/5b) e chama `editar($flow->id)` — o "redirect" natural do componente
(lista e editor são o mesmo componente Livewire, como o `novoFluxo` já fazia) — com toast
orientando a revisar. Key desconhecida: toast de erro, nada criado.

## Testes — `tests/Feature/FlowTemplateTest.php` (10)
1. **`todos_os_templates_do_catalogo_instanciam_fluxos_validos`** (destaque — integridade do
   catálogo): itera `all()` e instancia cada um; valida enabled, gatilho, raiz menu com opções,
   todo destino de opção resolve no mesmo fluxo, handoff/final sem opções, handoff com message,
   nenhum nó órfão, ≥1 handoff por template. Template malformado no futuro quebra aqui.
2. `instanciar_clinica_cria_o_shape_esperado_na_conta_ativa` — 5 nós (1 menu, 2 final, 2 handoff),
   opções 1–4 com kinds certos, `account_id` da conta ativa, `enabled=true`.
3. `fluxo_instanciado_abre_no_editor_sem_quebrar` — abre no editor 5b com buffers do handoff.
4. `usar_template_na_ui_instancia_e_abre_o_editor` — lista mostra o catálogo; `usarTemplate`
   cria e seta `editingFlowId` (redirect pro editor).
5. `usar_template_desconhecido_nao_cria_nada`.
6. **`handoff_do_fluxo_instanciado_executa_os_efeitos`** (destaque — integração com o motor da
   Fatia 5, mesmo caminho da `FlowHandoffTest`): entra pelo gatilho `menu`, escolhe `1` → mensagem
   enviada, `auto_reply_mode='off'`, card em `em_atendimento`, sessão `handed_off` do fluxo novo.
   **Sem** `default_flow_id` — entrada por gatilho do próprio template.
7. **`isolamento_instanciar_na_conta_a_nao_toca_a_conta_b`** (destaque) — B inalterada; linhas
   todas na A.
8. `instanciar_o_mesmo_template_duas_vezes_sufixa_o_nome` — "Clínica / consultório" e "… (2)",
   segunda instância íntegra.
9. `instanciar_nao_seta_default_flow_id` — null permanece null; padrão pré-existente permanece.
10. `blueprint_com_sub_menu_instancia_em_profundidade` — recursão com profundidade > 1 (catálogo
    fake), parent/next encadeados certos.

## Contagem
Antes: **724 verdes / 2717 assertions**. Depois: **734 verdes / 2847 assertions** (+10).
Suíte inteira **sequencial** verde (23s); `FlowHandoffTest`, `FlowEditorHandoffTest`, `FluxosTest`
sem alteração e verdes.

## Confirmações
- `default_flow_id` **não** é setado na instanciação (provado no teste 9).
- Escopo por conta: `account_id` explícito no `Flow`; filhas escopadas pela FK; isolamento provado.
- Motor (Fatia 5) e internas do editor (5b) intocados: diff só adiciona `usarTemplate` + variável
  `templates` no `render()` do `Fluxos` e a seção de cards na view.
- Zero migration; sem seed (Fatia 8) e sem warmup — fora de escopo respeitado.

## Commit
Local, sem push (Fabio empurra). Hash reportado na resposta.
