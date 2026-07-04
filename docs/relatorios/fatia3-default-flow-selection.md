# Fatia 3 — Seleção do Fluxo Padrão na UI (isolada, validada server-side) — 2026-07-04

**Status: ENTREGUE.** Baseline 679 → **684 verdes** (+5, 2571 assertions), `TenantIsolationTest` 28.
**Zero migration** (coluna da Fatia 1). **Pipeline segue INERTE** — o robô não lê a flag; a leitura
no ingest é a Fatia 4.

## Git no início
`working tree clean`, HEAD `cac6f72` (fatia 2).

## Onde entrou o seletor (e por quê)
**Reuso do `Configuracoes`** (Livewire), como o prompt indicou — é o lugar natural: já edita
`reply_policy`/freios com o resolver isolado correto. O `<select>` entrou no **card dos freios**,
logo após "Politica de resposta" (`resources/views/livewire/configuracoes.blade.php`, após a linha
do `@error('reply_policy')`), salvo pelo **mesmo `save()`** do card. Nenhuma surface nova.

## Lista dos fluxos habilitados da conta
`Configuracoes::render()`:
```php
$fluxosDisponiveis = Flow::query()->where('enabled', true)->orderBy('name')->get(['id','name']);
```
`Flow` é escopado por `BelongsToAccount` (AccountContext) — a lista só contém fluxos da conta ativa
(mesmo padrão das telas de Fluxos). Opção vazia "Nenhum" (→ null) no topo do select.

## Validação de posse server-side (o ponto de segurança) — regra exata
Em `Configuracoes::rules()`, `default_flow_id` = `['nullable', 'integer', closure]`:
```php
$ok = Flow::query()
    ->where('account_id', app(AccountContext::class)->id()) // explicito (never trust ambiente)
    ->where('enabled', true)
    ->whereKey((int) $value)->exists();
if (! $ok) $fail('Fluxo invalido — escolha um fluxo habilitado da sua conta.');
```
- **Dupla defesa:** `where('account_id', contaAtiva)` explícito **por cima** do escopo global
  `BelongsToAccount` — um `flow_id` de outro tenant adulterado no request é **rejeitado sem
  persistir** (e a mensagem não vaza existência de fluxos alheios).
- **Exceção única e deliberada:** se o valor postado **é o já salvo** (`(int)$value === (int)$atual`),
  passa — cobre o edge do fluxo desabilitado depois de escolhido (salvar a tela sem mexer no select
  não pode quebrar). Escolha **nova** sempre exige posse + habilitado.

## Persistência (resolver reusado)
No `save()` existente: `'default_flow_id' => $this->default_flow_id ?: null` dentro do
`$this->settings()->update([...])` — o MESMO `AutoReplySetting::firstOrCreate(['account_id' =>
AccountContext::id()])` das fatias anteriores. "Nenhum" grava `null`.

## Edge case (default desabilitado/ausente)
Se `default_flow_id` salvo aponta um fluxo fora da lista de habilitados, o `render()` o carrega
(escopado) e o select o exibe **marcado "(desabilitado)"** — a tela não mente nem quebra; salvar
mantendo o valor passa (exceção da regra); re-selecionar outro habilitado ou "Nenhum" resolve.
Fluxo **deletado** → FK zera pra null (`ON DELETE SET NULL` da Fatia 1, reasserido em teste).

## Componente de UI
`<select>` nativo com as classes padrão do card (o mesmo estilo do select de `reply_policy` ao
lado) + `x-info-tip` com **texto neutro** ("Fluxo usado como porta de entrada no modo automatico...")
— **sem** promessa comportamental (o robô ainda não age; Fatia 4). Não usei `flux:select` pra manter
coerência visual com os selects existentes do card.

## Testes (`tests/Feature/DefaultFlowSelectionTest.php`, 5)
1. **Persistência:** fluxo habilitado da conta → salvo; "Nenhum" → null (recarregado do banco).
2. **Isolamento + validação (crítico):** contas A e B com fluxos; contexto A: lista mostra só
   `Fluxo-Da-A` (não `Fluxo-Da-B`); **postar o id do fluxo de B → `assertHasErrors` e nada
   persiste**; salvar em A não afeta o `default_flow_id` de B.
3. **Fluxo desabilitado:** não listado; escolhê-lo como novo valor → rejeitado, nada persiste.
4. **Edge do default desabilitado:** aparece "(desabilitado)" no select, `assertSet` do valor atual,
   e salvar mantendo passa (valor preservado).
5. **Delete do fluxo apontado:** `default_flow_id` zera pra null.

## Contagem
Antes: **679 verdes / 2549 assertions**. Depois: **684 verdes / 2571 assertions** (+5).
`TenantIsolationTest` 28 verde. Suíte sequencial, zero regressão.

## Confirmação explícita
**Nenhum ponto do pipeline lê `operation_mode`/`default_flow_id` nesta fatia.** Grep fora de
`AutoReplySetting`/`OperationMode` (enum)/`OperationModeToggle`/`Configuracoes` (UI de escrita) =
**vazio**. Catch-all e confirmação comportamental = Fatia 4.

## Commit
Local, sem push (Fabio empurra). Hash reportado na resposta.
