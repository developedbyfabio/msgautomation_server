# Fatia 9 — Ativação do Modo Automático: diagnóstico do aviso + modal com seleção de fluxo + switch + badge "Padrão" — 2026-07-05

Git no início: HEAD `0be8840` (fatia 8), working tree limpo exceto dois relatórios untracked
pré-existentes (`diagnostico-modo-automatico.md`, `fatia8-STOP-pre-requisito.md` — não são desta
fatia e ficaram fora do commit). Baseline: **744 verdes / 2899 assertions**.

---

## Parte A — Diagnóstico do aviso reportado

**Conclusão: causa (a) — DADO, não bug de código.** UX confusa (habilitar ≠ padrão); o aviso estava
tecnicamente correto.

**Valores reais encontrados na conta 1** (SELECT de leitura apenas, via tinker):

```
operation_mode:  personal
default_flow_id: NULL
flow #2 Atendimento (exemplo)        enabled=false
flow #4 Novo fluxo                   enabled=false
flow #5 Clínica / consultório        enabled=false
flow #6 Salão de beleza / barbearia  enabled=false
flow #7 Comércio / estabelecimento   enabled=true   <-- o fluxo que o Fabio HABILITOU
```

Ou seja: o Fabio ligou o fluxo #7 na aba Fluxos, mas **nunca o selecionou como padrão** em
Configurações — habilitar um fluxo não grava `default_flow_id`. Com default `NULL`, a variante de
aviso era a resposta correta do código.

**Código verificado** (`OperationModeToggle::toggle()`, estado pré-fatia):

```php
// Ligar (Personal -> Auto): NAO persiste ainda — abre a confirmacao com a
// variante certa (fluxo padrao valido = defaultFlow existe E enabled).
$flow = $settings->defaultFlow;
$this->temFluxoValido = $flow !== null && (bool) $flow->enabled;
$this->confirming = true;
```

`temFluxoValido` era capturado **no clique** (não no mount), com `$this->settings()` re-resolvendo a
conta ativa a cada request (`firstOrCreate` por `account_id` do `AccountContext`) — sem cache stale,
sem conta errada. A hipótese alternativa (bug de detecção) foi descartada com evidência: dado
`default_flow_id = NULL`, `temFluxoValido = false` é o resultado correto.

A Parte B ataca a causa real: a distinção enabled vs padrão era invisível e o caminho para definir o
padrão (Configurações) não era descobrível a partir do modal.

---

## Parte B — Redesign implementado

### Switch visual (header)
- `flux:switch` **não existe no Flux free** (verificado em `vendor/livewire/flux/stubs/.../flux/` —
  switch é componente Pro). Escolhido: **switch em Tailwind puro** dentro do mesmo `<button>`
  Livewire (track `rounded-full` + thumb com `translate-x`, verde quando auto), com
  `role="switch"`/`aria-checked`, label textual do modo atual ao lado, tooltip e `aria-label`
  preservados (o smoke test do header continua assertando `Alternar modo de operacao`).
- Continua 100% server-side por conta (`wire:click="toggle"`, `wire:loading` desabilita e mostra
  spinner). Nada de localStorage/Alpine para estado.

### Modal de ativação com seleção de fluxo (`OperationModeToggle`)
- **Caso 1 — há fluxo habilitado:** copy de efeito + `<select wire:model.live="fluxoEscolhido">`
  listando **só** fluxos habilitados da conta ativa (`where account_id` explícito **além** do escopo
  `BelongsToAccount` — defesa em profundidade, mesma disciplina das rules da Fatia 3). Pré-seleção:
  `default_flow_id` atual quando aponta fluxo válido/habilitado; senão placeholder "Escolha um
  fluxo...". Botão Ativar `@disabled` sem seleção **e** rejeição server-side em
  `confirmarAtivacao()`: sem seleção, id de outra conta, desabilitado ou inexistente →
  `addError('fluxoEscolhido', 'Escolha um fluxo habilitado da sua conta.')` (mensagem única — não
  vaza existência), **nada persiste**, modal segue aberto. Confirmar grava `default_flow_id` +
  `operation_mode=auto` **no mesmo `update()`**. Cancelar não persiste nada (nem modo, nem fluxo).
- A validação re-lê o banco **no momento da confirmação** (não confia no snapshot do clique que
  abriu o modal) — fluxo desabilitado entre o clique e o confirmar é rejeitado.
- **Caso 2 — nenhum fluxo habilitado:** única variante de aviso restante ("não responderá nada"),
  link para a aba **Fluxos** (antes apontava Configurações), botão "Ativar mesmo assim" grava só
  `operation_mode=auto` (`default_flow_id` fica como está — degradação graciosa da Fatia 4
  preservada).
- **Desligar:** imediato, sem modal (inalterado).
- A lista de fluxos do select só é consultada com o modal aberto (header renderiza em toda página —
  sem query extra no caminho comum).
- Verificação extra de runtime: coerção do Livewire 3 confirmada com teste descartável — o select
  postando `''` (placeholder) vira `null` no `?int $fluxoEscolhido`, e string numérica vira int
  (comportamento real do browser coberto).

### Badge "Padrão" na aba Fluxos
- `Fluxos::render()` passou a ler (leitura apenas) o `default_flow_id` da conta ativa
  (`AutoReplySetting::query()->where('account_id', $accountId)->value(...)`).
- Na listagem (`fluxos.blade.php`, ao lado do nome): pill indigo "**Padrao**" no fluxo default;
  se o default está desabilitado, pill âmbar "**Padrao (desabilitado)**" com ícone de atenção — o
  estado quebrado fica visível sem abrir o modal. Nenhum badge quando default null.
- Escrita de `default_flow_id` segue **só** em Configurações (Fatia 3, intacta) e no modal novo.

---

## Testes da Fatia 4b ajustados (mudança de design, não regressão)

Em `OperationModeToggleConfirmTest`:

1. `test_copy_padrao_quando_ha_fluxo_valido` → substituído por
   `test_modal_lista_so_fluxos_habilitados_da_conta_ativa`. Justificativa: a flag `temFluxoValido`
   deixou de existir (a variante agora deriva da lista fresca de fluxos habilitados) e a copy mudou
   ("pelo fluxo selecionado"); o caso novo cobre mais — isolamento do select (habilitado aparece,
   desabilitado e fluxo de outra conta não).
2. `test_aviso_quando_nao_ha_fluxo_padrao` → substituído por
   `test_sem_nenhum_fluxo_habilitado_avisa_e_permite_ativar`. Justificativa: por design, a variante
   de aviso agora só existe quando **nenhum** fluxo está habilitado (copy nova: "nao tem nenhum
   fluxo habilitado"); "ativar mesmo assim" continua coberto (auto com default null).
3. `test_aviso_quando_fluxo_padrao_esta_desabilitado` → absorvido pelos casos novos. Justificativa:
   o cenário "default aponta fluxo desabilitado" agora se divide em dois comportamentos —
   com outro fluxo habilitado vira placeholder no select
   (`test_preselecao_default_valido_vem_selecionado_e_invalido_vem_placeholder`); sem nenhum
   habilitado cai na variante de aviso (teste do item 2). O assert antigo (`temFluxoValido=false`)
   referenciava a flag extinta.
4. `test_ligar_exige_confirmacao_confirmar_persiste_cancelar_mantem` — mantido com asserts a mais
   (cancelar também não altera o default). O confirmar funciona porque o default válido vem
   pré-selecionado.
5. `test_isolamento_confirmar_em_a_nao_altera_b` — mantido, estendido: A agora confirma **com**
   fluxo selecionado e o teste assert que nem `operation_mode` nem `default_flow_id` de B mudam.
6. `test_desligar_e_imediato_sem_confirmacao` — sem alteração.

`OperationModeToggleTest` (Fatia 2): **zero alteração** — os fluxos de `toggle` +
`confirmarAtivacao` sem nenhum fluxo habilitado caem no caso 2 (permitido) e o smoke do header
continua passando com o switch novo.

---

## Arquivos de teste novos/alterados

- `tests/Feature/OperationModeToggleConfirmTest.php` (alterado, 6 → 10 casos): confirmação
  persiste/cancela; select lista só habilitados da conta; pré-seleção (válido selecionado /
  inválido placeholder); confirmar sem seleção rejeitado server-side sem persistir; confirmar com
  seleção grava default+auto juntos; cancelar após selecionar não vaza nada pro banco; posse (id de
  outra conta e desabilitado rejeitados, nada persiste); sem habilitado avisa e permite; desligar
  imediato; isolamento A/B (modo e default).
- `tests/Feature/FluxosDefaultBadgeTest.php` (novo, 4 casos): badge só no fluxo default (contagem
  exata de 1 no HTML); "Padrao (desabilitado)" quando o default está off; nenhum badge com default
  null; isolamento (default de B não gera badge na listagem de A).

## Contagem de testes

| | testes | assertions |
|---|---|---|
| Antes | 744 | 2899 |
| Depois | **752** | **2943** |

Suíte inteira rodada **sequencial**, tudo verde — zero regressão fora dos ajustes deliberados
listados acima (OperationModeToggleConfirmTest: 6 casos viraram 10; +4 do badge).

## Confirmações explícitas

- **Pipeline/gate/catch-all/FlowEngine: nenhum diff.** Arquivos tocados: `app/Livewire/Fluxos.php`,
  `app/Livewire/OperationModeToggle.php`, as duas views correspondentes e os testes. Nada em
  `app/Jobs/`, `app/Whatsapp/Flows/FlowEngine.php` ou guards.
- **Seletor de Configurações (Fatia 3) intacto** — `Configuracoes.php` sem diff; mesma coluna
  `default_flow_id`, as duas escritas convergem na mesma validação de posse.
- Zero migration; isolamento por conta preservado (`AccountContext` + where explícito em toda
  leitura/escrita).

## Commit
Local, sem push (Fabio empurra). Hash reportado na resposta.
