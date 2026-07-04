# Fatia 5b — Handoff no editor de fluxos (authoring) — 2026-07-04

**Status: ENTREGUE.** Baseline 715 → **724 verdes** (+9, 2717 assertions). **Zero migration**
(kind `handoff` já existe desde a Fatia 5; tudo aqui é editor). **Motor da Fatia 5 intocado.**

## Git no início
`working tree clean` (fora relatórios untracked), HEAD `fc6dc68` (fatia 5).

## Estrutura do editor `Fluxos` (mapeada no 3.1)
O editor NÃO é limitado — encaixou sem forçar. `app/Livewire/Fluxos.php` edita **direto no
banco por ação** (sem árvore em array):
- **Criar nó:** só via destino de opção (`definirDestino` com `novo_menu`/`novo_final` cria
  FlowNode filho e liga `next_node_id`). `novoFluxo` cria o root (menu).
- **Renderizar:** `treeOrdered()` (DFS por `parent_node_id`) → view itera; buffers por id
  (`nodeMsg[]`, `nodeKind[]`, `optBuf[]`) carregados em `loadNodeBuffers` — **agnósticos a
  kind**, então um handoff pré-existente já carregava; o que quebrava era só a UI (select
  sem a opção → mostraria vazio; preview rotulava como "menu"; warning falso de menu sem opção).
- **Salvar:** `salvarNo` (message + kind, com coerção `'final' ? : 'menu'` — o ponto exato
  do encaixe), `salvarOpcao`, `definirDestino` (id existente validado **no mesmo fluxo**).
- **Posse:** `ownNode`/`ownOption` — `whereHas('flow', account_id = contexto)`. Toda ação
  passa por eles (disciplina da Fatia 3 já presente; reusada, não recriada).

## Onde o handoff se encaixou (tudo aditivo, mesmo padrão)
1. **`salvarNo`:** coerção virou `in_array(kind, ['final','handoff']) ? kind : 'menu'` +
   bloco de validação do handoff (abaixo). `menu|final` seguem o caminho antigo intacto.
2. **`definirDestino`:** `novo_handoff` no mesmo branch de criação (match no kind/message
   default "Um atendente vai te responder em breve."). Destino por **id existente** já
   aceitava qualquer nó do mesmo fluxo — handoff entrou de graça (só ganhou rótulo na view).
3. **`addOpcao`:** guarda server-side — nó handoff recusa opção (toast). A UI já esconde a
   seção de opções, mas ação Livewire é forjável → guarda no servidor obrigatória.
4. **View:** option `handoff (encerra e chama humano)` no select de kind; seção de opções
   escondida pra `final` E `handoff`; option `+ handoff (chamar atendente)` no optgroup
   "Criar e ligar"; nota âmbar no nó handoff explicando os efeitos (mensagem + pausa +
   card Em atendimento + terminal); preview rotula `handoff:`.
5. **`flowWarnings`:** handoff sem opção NÃO é mais "menu sem opção" (falso positivo);
   avisos novos: handoff **com** opções (dados de fora — ignoradas na execução) e handoff
   **sem mensagem** (pré-existente malformado — o salvarNo rejeita, mas dado externo pode chegar).

## Validações (message obrigatória, terminal) e escopo
- **Terminal:** `salvarNo` com kind=handoff e nó **com opções** → rejeita com toast claro
  ("remova as opções antes"), **reverte o buffer do select** (o nó não virou handoff) e não
  persiste. `addOpcao` em handoff → recusa. Opções nunca são apagadas silenciosamente.
- **Message obrigatória:** kind=handoff com message vazia/whitespace → rejeita com toast
  (explica que é o aviso ao contato), não persiste; buffer mantido pro usuário corrigir.
- **Escopo por conta:** nenhuma verificação nova foi preciso inventar — todas as ações novas
  passam pelos MESMOS `ownNode`/`ownOption` (account do contexto) e o destino por id segue
  validado `where('flow_id', $node->flow_id)` (nó de outro fluxo/conta → destino limpo).

## Render/edição de handoff pré-existente
Garantido por construção (buffers agnósticos) e **provado por teste**: fluxo montado
programaticamente (exatamente como um template da Fatia 7 instanciará — `FlowNode::create`
kind=handoff + opção apontando) abre no editor sem erro, exibe kind/message nos buffers,
`assertSee` da mensagem, edita e salva preservando o kind.

## Testes — `tests/Feature/FlowEditorHandoffTest.php` (9)
1. `destino_novo_handoff_cria_no_handoff_e_liga_a_opcao` — cria via editor: kind, message
   default, parent, `next_node_id` ligado, nasce sem opções.
2. `trocar_no_existente_pra_handoff_com_mensagem_persiste` — salvarNo + round-trip (reabrir
   reflete buffers).
3. `opcao_pode_apontar_pra_handoff_existente` — destino por id + o handoff aparece no select
   (`no #N (handoff)`).
4. **`fluxo_com_handoff_pre_existente_abre_e_edita_sem_quebrar`** — o caso-template (destaque).
5. `handoff_sem_mensagem_e_rejeitado_sem_persistir`.
6. `no_com_opcoes_nao_pode_virar_handoff` — rejeitado, buffer revertido, opção intacta.
7. `add_opcao_em_handoff_e_recusado`.
8. **`isolamento_nao_edita_nem_liga_nos_de_outra_conta`** (destaque) — 4 vetores: salvarNo em
   nó da B (no-op), definirDestino em opção da B (no-op, nada criado na B), opção de A
   apontando pra nó da B (destino limpo), addOpcao em nó da B (no-op).
9. `menu_e_final_no_editor_seguem_como_antes` — regressão leve (novo_final; menu aceita
   message vazia como sempre).

## Contagem
Antes: **715 verdes / 2685 assertions**. Depois: **724 verdes / 2717 assertions** (+9).
Suíte inteira sequencial verde; `FluxosTest` (regressão do editor) verde sem alteração.

## Confirmação de escopo
`git status`: **só** `app/Livewire/Fluxos.php`, `resources/views/livewire/fluxos.blade.php`
e `tests/Feature/FlowEditorHandoffTest.php`. `FlowEngine`/`Sender`/`AntiBanGuard`/jobs — intocados.
Fora de escopo respeitado: templates (7), seed (8), warmup — não tocados.

## Commit
Local, sem push (Fabio empurra). Hash reportado na resposta.
