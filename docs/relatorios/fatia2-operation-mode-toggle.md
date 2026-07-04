# Fatia 2 — Toggle de Modo no cabeçalho (Livewire, per-account, ISOLADO) — 2026-07-04

**Status: ENTREGUE.** Baseline 675 → **679 verdes** (+4, 2549 assertions), `TenantIsolationTest` 28.
Zero migration (colunas da Fatia 1). **Pipeline segue INERTE** — nenhum ponto do robô lê a flag
(grep confirmado vazio fora do model/enum/toggle). Persistência **server-side por conta** (não
localStorage).

## Git no início
`working tree clean`, HEAD `480c439` (fatia 1).

## Conta ativa + AccountContext no lifecycle de update do Livewire
`SetAccountContext` está **appendado ao grupo `web`** (`bootstrap/app.php:36`). Os updates do
Livewire (`POST /livewire/update`) passam pelo grupo `web`, então o contexto é re-resolvido **em
todo request**, inclusive nos `wire:click` — o middleware re-deriva a conta do vínculo do usuário
autenticado + sessão a cada request (autorização por request, comentário do próprio middleware).
Prova de vida: `Configuracoes` já grava settings em actions Livewire (`requestKillSwitch`,
`toggleMediaAutodownload`) por esse mesmo caminho, em produção. **Decisão: usar `AccountContext`**
(não precisei derivar manualmente do usuário); o componente re-resolve a conta **dentro de cada
método** (nunca cacheia entre requests).

## Resolver de settings reusado
O MESMO do `Configuracoes::settings()`:
`AutoReplySetting::firstOrCreate(['account_id' => app(AccountContext::class)->id()])` — model
escopado por `BelongsToAccount`, `account_id` explícito. Nenhuma query nova inventada.

## Componente
- `app/Livewire/OperationModeToggle.php`: `mount()` carrega o estado (`auto` = modo === Auto);
  `toggle()` re-resolve os settings da conta ativa, alterna Personal↔Auto, persiste
  (`$settings->update([...])`), **re-lê do banco** e emite toast discreto. `label()` usa o
  `OperationMode::label()` da Fatia 1.
- `resources/views/livewire/operation-mode-toggle.blade.php`: botão no mesmo estilo do dark toggle
  ao lado (mesmas classes/tamanho), ícone `user` (Pessoal) / `bolt` (Automático) + label,
  `wire:loading.attr="disabled"` + spinner (evita duplo clique). Tooltip **neutro** ("Modo de
  operacao da conta (atual: X)") — **sem** promessa comportamental (Fatia 4).

## Header
`resources/views/components/layouts/app.blade.php` (~linha 123): `<livewire:operation-mode-toggle />`
entre o badge "Robo" e o dark toggle, dentro de `@auth`. (O layout já é só de páginas autenticadas;
o `@auth` é cinto extra.)

## Role-gating — decisão registrada
O conceito de papel por conta **existe** (pivot `account_user.role` = owner|operador, MT-1), mas
**não é usado para autorização em lugar NENHUM do painel** (grep vazio — nem o kill switch, mais
perigoso que este toggle, é gated por papel). Criar aqui o primeiro gate por papel seria
inconsistente e um sistema novo de permissão nesta fatia (proibido). **Decisão:** exibido para
usuários autenticados da conta; **role-gating registrado como refinamento futuro** (idealmente
aplicado de uma vez a Configurações inteiras, não só a este botão).

## Componente de UI
`flux:switch` existe no free, mas o padrão do header (dark toggle e ações) é **botão Tailwind
compacto** — usei o mesmo estilo do dark toggle (coerência visual lado a lado). Registrado.

## Sem aviso comportamental
Nenhum modal/confirmação "toda mensagem será respondida" — seria enganoso (o robô não muda nesta
fatia). Só o tooltip neutro. A confirmação forte entra na Fatia 4.

## Testes (`tests/Feature/OperationModeToggleTest.php`, 4)
1. **Liga/desliga + persistência:** Personal → toggle → Auto no banco; toggle → Personal de volta
   (recarregado via `withoutAccountScope` com `where account_id`).
2. **Isolamento (crítico):** contas A e B com settings Personal; contexto = A; `toggle()` →
   **A = Auto, B PERMANECE Personal** (e sem linha extra em B). Estilo TenantIsolationTest.
3. **Conta sem settings:** mount cria via `firstOrCreate` **escopado** (default Personal), alterna;
   a outra conta segue **sem** settings (nada vazou).
4. **Smoke full-page (middleware real):** usuário autenticado (vínculo + sessão), conta com canal,
   modo Auto no banco → GET `/perfil` renderiza o toggle no header com o estado do banco
   ("Automatico").

## Contagem
- Antes: **675 verdes / 2532 assertions**. Depois: **679 verdes / 2549 assertions** (+4 testes).
- `TenantIsolationTest`: 28 verde. Suíte inteira sequencial, zero regressão.

## Confirmação explícita
**Nenhum ponto do pipeline lê `operation_mode`/`default_flow_id` nesta fatia.** Grep por
`operation_mode|default_flow_id|OperationMode|defaultFlow` fora de
`AutoReplySetting`/`OperationMode` (enum)/`OperationModeToggle` = **vazio**. Catch-all = Fatia 4;
seleção de fluxo padrão = Fatia 3.

## Commit
Local, sem push (Fabio empurra). Hash: ver `git log` — reportado na resposta.
