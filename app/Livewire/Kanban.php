<?php

namespace App\Livewire;

use App\Kanban\BoardProvisioner;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\BoardRule;
use App\Models\Card;
use App\Models\CardTransition;
use App\Tenancy\AccountContext;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Kanban K-2 — UI do board (/kanban). O Kanban segue OBSERVADOR PURO: a tela mostra
 * e move cards (acao humana, cause=manual), edita colunas e regras de movimento —
 * NUNCA envia mensagem, NUNCA decide resposta.
 *
 * - Movimento manual via menu "Mover para..." (sem drag-and-drop — sem lib nova;
 *   registrado como melhoria futura, mesma decisao do editor de fluxos).
 * - Slugs de coluna sao ESTAVEIS: renomear muda so o nome; regras nunca quebram.
 * - Colunas system (5 da D4) nao sao excluiveis; custom so vazia e sem regra.
 * - Edicao de regra vale so pra eventos FUTUROS (nada reprocessa historico).
 */
#[Layout('components.layouts.app')]
class Kanban extends Component
{
    public string $search = '';
    public ?int $filterTagId = null; // T-1: filtro por tag junto da busca

    // Historico do card (modal).
    public ?int $historyCardId = null;

    // Gerenciar colunas (modal).
    public bool $showColumns = false;
    /** @var array<int,string> id => nome (edicao) */
    public array $colNames = [];
    public string $newColName = '';

    // Regras de movimento (modal lista + modal form).
    public bool $showRules = false;
    public bool $showRuleForm = false;
    public ?int $editingRuleId = null;
    public string $rEvent = 'mensagem_recebida';
    public string $rCondition = 'none';
    public string $rConditionSlug = '';
    public string $rIntent = '';                 // T-1: condicao por intent (ia_decisao)
    public string $rAction = 'move_column';      // T-1: move_column | add_tag | remove_tag
    public ?int $rToColumnId = null;
    public ?int $rTagId = null;                  // T-1: alvo das acoes de tag
    public bool $rActive = true;

    /** T-1 — acoes disponiveis da regra de movimento. */
    public const ACOES = [
        'move_column' => 'Mover o card pra coluna (first-match: a primeira vence)',
        'add_tag' => 'Aplicar tag no contato (cumulativa: todas que casam aplicam)',
        'remove_tag' => 'Remover tag do contato (cumulativa)',
    ];

    // Confirmacao leve pra mexer em regra DEFAULT.
    public ?int $confirmingDefaultRuleId = null;
    public string $confirmingDefaultAction = ''; // toggle | edit

    /** Descricao pt-BR dos eventos emitidos pelo pipeline (select da regra). */
    public const EVENTOS = [
        'mensagem_recebida' => 'Mensagem recebida — o contato mandou mensagem',
        'resposta_enviada' => 'Resposta automatica enviada — regra, fluxo, IA ou aprovacao no /revisao',
        'envio_manual' => 'Envio manual — voce respondeu pelo sistema',
        'fluxo_no' => 'Fluxo avancou — a conversa chegou num no do fluxo',
        'ia_decisao' => 'IA decidiu — a IA registrou uma decisao (respondeu/escalou/silenciou)',
    ];

    /** Condicoes basicas suportadas pelo motor (uma por regra na UI). */
    public const CONDICOES = [
        'none' => 'Sempre (sem condicao)',
        'card_absent' => 'Card ainda NAO existe',
        'card_present' => 'Card ja existe',
        'in_column' => 'Card esta na coluna...',
        'not_in_column' => 'Card NAO esta na coluna...',
        'intent' => 'Intent da IA e... (so evento "IA decidiu"; casa quando a IA RESPONDEU com esse intent)',
    ];

    // ---- board -----------------------------------------------------------------

    private function board(): Board
    {
        // Board default da conta do contexto (provisiona se faltar — contas antigas).
        return Board::query()->where('is_default', true)->first()
            ?? app(BoardProvisioner::class)->ensureDefaultBoard(app(AccountContext::class)->id());
    }

    private function isSystemColumn(BoardColumn $col): bool
    {
        return array_key_exists($col->slug, BoardProvisioner::DEFAULT_COLUMNS);
    }

    // ---- movimento manual --------------------------------------------------------

    public function moveCard(int $cardId, int $columnId): void
    {
        $board = $this->board();
        $card = Card::query()->where('board_id', $board->id)->find($cardId);
        $col = $board->columns()->where('id', $columnId)->first();
        if (! $card || ! $col) {
            return;
        }

        // Mesma coluna = no-op (sem transicao), como no motor.
        if ((int) $card->column_id === (int) $col->id) {
            return;
        }

        $de = (int) $card->column_id;
        $card->update(['column_id' => $col->id, 'last_interaction_at' => now()]);
        CardTransition::create([
            'card_id' => $card->id,
            'from_column_id' => $de,
            'to_column_id' => $col->id,
            'cause' => 'manual',
        ]);

        $this->dispatch('toast', message: 'Card movido para "' . $col->name . '".');
    }

    public function showHistory(int $cardId): void
    {
        $board = $this->board();
        if (Card::query()->where('board_id', $board->id)->whereKey($cardId)->exists()) {
            $this->historyCardId = $cardId;
        }
    }

    public function closeHistory(): void
    {
        $this->historyCardId = null;
    }

    // ---- gerenciar colunas ---------------------------------------------------------

    public function openColumns(): void
    {
        $this->colNames = $this->board()->columns()->pluck('name', 'id')->all();
        $this->newColName = '';
        $this->resetValidation();
        $this->showColumns = true;
    }

    public function closeColumns(): void
    {
        $this->showColumns = false;
        $this->colNames = [];
        $this->newColName = '';
        $this->resetValidation();
    }

    /** Renomear: SO o nome de exibicao — o slug (referencia das regras) fica intacto. */
    public function saveColumns(): void
    {
        $board = $this->board();
        foreach ($board->columns as $col) {
            $nome = trim((string) ($this->colNames[$col->id] ?? ''));
            if ($nome === '') {
                $this->addError('colNames.' . $col->id, 'Nome nao pode ficar vazio.');

                return;
            }
            if ($nome !== $col->name) {
                $col->update(['name' => $nome]);
            }
        }

        $this->closeColumns();
        $this->dispatch('toast', message: 'Colunas salvas.');
    }

    public function moveColumn(int $columnId, string $dir): void
    {
        $board = $this->board();
        $cols = $board->columns()->get()->values();
        $i = $cols->search(fn ($c) => (int) $c->id === $columnId);
        if ($i === false) {
            return;
        }
        $j = $dir === 'up' ? $i - 1 : $i + 1;
        if ($j < 0 || $j >= $cols->count()) {
            return;
        }

        // Swap de position (persistido; sem drag).
        $a = $cols[$i];
        $b = $cols[$j];
        [$pa, $pb] = [(int) $a->position, (int) $b->position];
        $a->update(['position' => $pb]);
        $b->update(['position' => $pa]);
    }

    public function addColumn(): void
    {
        $nome = trim($this->newColName);
        if ($nome === '' || mb_strlen($nome) > 40) {
            $this->addError('newColName', 'Informe um nome (ate 40 caracteres).');

            return;
        }

        $board = $this->board();
        // Slug custom UNICO e estavel (nunca colide com os system).
        $base = Str::slug($nome, '_') ?: 'coluna';
        $slug = $base;
        $n = 2;
        while (array_key_exists($slug, BoardProvisioner::DEFAULT_COLUMNS)
            || $board->columns()->where('slug', $slug)->exists()) {
            $slug = $base . '_' . $n++;
        }

        BoardColumn::create([
            'board_id' => $board->id,
            'slug' => $slug,
            'name' => $nome,
            'position' => (int) ($board->columns()->max('position') ?? 0) + 1,
        ]);

        $this->newColName = '';
        $this->colNames = $this->board()->columns()->pluck('name', 'id')->all();
        $this->dispatch('toast', message: 'Coluna criada.');
    }

    /** Excluir: SO coluna custom, vazia e sem regra apontando pra ela. */
    public function deleteColumn(int $columnId): void
    {
        $board = $this->board();
        $col = $board->columns()->where('id', $columnId)->first();
        if (! $col) {
            return;
        }

        if ($this->isSystemColumn($col)) {
            $this->dispatch('toast', message: 'Coluna padrao nao pode ser excluida (as regras de movimento dependem dela). Pode renomear a vontade.', type: 'error');

            return;
        }
        if (Card::query()->where('board_id', $board->id)->where('column_id', $col->id)->exists()) {
            $this->dispatch('toast', message: 'Coluna tem cards — mova-os antes de excluir.', type: 'error');

            return;
        }
        if (BoardRule::query()->where('board_id', $board->id)->where('to_column_id', $col->id)->exists()) {
            $this->dispatch('toast', message: 'Ha regra de movimento apontando pra esta coluna — ajuste a regra antes.', type: 'error');

            return;
        }

        $col->delete();
        $this->colNames = $this->board()->columns()->pluck('name', 'id')->all();
        $this->dispatch('toast', message: 'Coluna excluida.');
    }

    // ---- regras de movimento ---------------------------------------------------------

    public function openRules(): void
    {
        $this->showRules = true;
    }

    public function closeRules(): void
    {
        $this->showRules = false;
        $this->cancelRuleForm();
    }

    /** Desativar regra DEFAULT pede confirmacao; reativar e regra custom, direto. */
    public function toggleRule(int $ruleId): void
    {
        $rule = $this->rule($ruleId);
        if (! $rule) {
            return;
        }

        if ($rule->is_default && $rule->active) {
            $this->confirmingDefaultRuleId = $ruleId;
            $this->confirmingDefaultAction = 'toggle';

            return;
        }

        $rule->update(['active' => ! $rule->active]);
        $this->dispatch('toast', message: $rule->active ? 'Regra ativada.' : 'Regra desativada (vale pra eventos futuros).');
    }

    public function startRuleEdit(int $ruleId): void
    {
        $rule = $this->rule($ruleId);
        if (! $rule) {
            return;
        }

        if ($rule->is_default && $this->confirmingDefaultRuleId !== $ruleId) {
            $this->confirmingDefaultRuleId = $ruleId;
            $this->confirmingDefaultAction = 'edit';

            return;
        }

        $this->confirmingDefaultRuleId = null;
        $this->confirmingDefaultAction = '';
        $this->editingRuleId = $rule->id;
        $this->rEvent = (string) $rule->event_type;
        [$this->rCondition, $this->rConditionSlug, $this->rIntent] = $this->parseCondition((array) $rule->conditions);
        $this->rAction = (string) ($rule->action_type ?: 'move_column');
        $this->rToColumnId = $rule->to_column_id !== null ? (int) $rule->to_column_id : null;
        $this->rTagId = $rule->tag_id !== null ? (int) $rule->tag_id : null;
        $this->rActive = (bool) $rule->active;
        $this->resetValidation();
        $this->showRuleForm = true;
    }

    public function confirmDefaultAction(): void
    {
        $id = $this->confirmingDefaultRuleId;
        $acao = $this->confirmingDefaultAction;
        if ($id === null) {
            return;
        }

        if ($acao === 'toggle') {
            $rule = $this->rule($id);
            $rule?->update(['active' => false]);
            $this->confirmingDefaultRuleId = null;
            $this->confirmingDefaultAction = '';
            $this->dispatch('toast', message: 'Regra padrao desativada (vale pra eventos futuros).');

            return;
        }

        // edit: reabre ja confirmado.
        $this->startRuleEdit($id);
    }

    public function cancelDefaultAction(): void
    {
        $this->confirmingDefaultRuleId = null;
        $this->confirmingDefaultAction = '';
    }

    public function startRuleCreate(): void
    {
        $this->editingRuleId = null;
        $this->rEvent = 'mensagem_recebida';
        $this->rCondition = 'none';
        $this->rConditionSlug = '';
        $this->rIntent = '';
        $this->rAction = 'move_column';
        $this->rToColumnId = null;
        $this->rTagId = null;
        $this->rActive = true;
        $this->resetValidation();
        $this->showRuleForm = true;
    }

    public function cancelRuleForm(): void
    {
        $this->showRuleForm = false;
        $this->editingRuleId = null;
        $this->resetValidation();
    }

    public function saveRule(): void
    {
        $board = $this->board();

        if (! array_key_exists($this->rEvent, self::EVENTOS)) {
            $this->addError('rEvent', 'Evento invalido.');

            return;
        }
        if (! array_key_exists($this->rAction, self::ACOES)) {
            $this->addError('rAction', 'Acao invalida.');

            return;
        }

        // Alvo OBRIGATORIO do tipo certo (T-1): coluna pra move_column; tag pras acoes de tag.
        $destino = null;
        $tag = null;
        if ($this->rAction === 'move_column') {
            $destino = $this->rToColumnId ? $board->columns()->where('id', $this->rToColumnId)->first() : null;
            if (! $destino) {
                $this->addError('rToColumnId', 'Escolha a coluna de destino.');

                return;
            }
        } else {
            $tag = $this->rTagId ? \App\Models\Tag::query()->find($this->rTagId) : null;
            if (! $tag) {
                $this->addError('rTagId', 'Escolha a tag.');

                return;
            }
        }

        if (! array_key_exists($this->rCondition, self::CONDICOES)) {
            $this->addError('rCondition', 'Condicao invalida.');

            return;
        }
        if (in_array($this->rCondition, ['in_column', 'not_in_column'], true)
            && ! $board->columns()->where('slug', $this->rConditionSlug)->exists()) {
            $this->addError('rConditionSlug', 'Escolha a coluna da condicao.');

            return;
        }
        if ($this->rCondition === 'intent') {
            if ($this->rEvent !== 'ia_decisao') {
                $this->addError('rCondition', 'Condicao por intent so vale pro evento "IA decidiu".');

                return;
            }
            if (trim($this->rIntent) === '') {
                $this->addError('rIntent', 'Informe o intent.');

                return;
            }
        }

        $dados = [
            'event_type' => $this->rEvent,
            'conditions' => $this->buildCondition(),
            'action_type' => $this->rAction,
            'to_column_id' => $destino?->id,
            'tag_id' => $tag?->id,
            'active' => $this->rActive,
        ];

        if ($this->editingRuleId) {
            $this->rule($this->editingRuleId)?->update($dados);
        } else {
            BoardRule::create(array_merge($dados, [
                'board_id' => $board->id,
                'is_default' => false,
                'position' => (int) ($board->rules()->max('position') ?? 0) + 1,
            ]));
        }

        $this->cancelRuleForm();
        $this->dispatch('toast', message: 'Regra salva (vale pra eventos futuros).');
    }

    public function moveRule(int $ruleId, string $dir): void
    {
        $board = $this->board();
        $rules = $board->rules()->get()->values();
        $i = $rules->search(fn ($r) => (int) $r->id === $ruleId);
        if ($i === false) {
            return;
        }
        $j = $dir === 'up' ? $i - 1 : $i + 1;
        if ($j < 0 || $j >= $rules->count()) {
            return;
        }

        $a = $rules[$i];
        $b = $rules[$j];
        [$pa, $pb] = [(int) $a->position, (int) $b->position];
        $a->update(['position' => $pb]);
        $b->update(['position' => $pa]);
    }

    private function rule(int $id): ?BoardRule
    {
        return BoardRule::query()->where('board_id', $this->board()->id)->find($id);
    }

    /** @return array{0:string,1:string,2:string} [tipo, slug, intent] */
    private function parseCondition(array $cond): array
    {
        if (($cond['card'] ?? null) === 'absent') {
            return ['card_absent', '', ''];
        }
        if (($cond['card'] ?? null) === 'present') {
            return ['card_present', '', ''];
        }
        if (isset($cond['card_in_column'])) {
            return ['in_column', (string) $cond['card_in_column'], ''];
        }
        if (isset($cond['not_in_column'])) {
            return ['not_in_column', (string) $cond['not_in_column'], ''];
        }
        if (isset($cond['intent'])) {
            return ['intent', '', (string) $cond['intent']];
        }

        return ['none', '', ''];
    }

    private function buildCondition(): ?array
    {
        return match ($this->rCondition) {
            'card_absent' => ['card' => 'absent'],
            'card_present' => ['card' => 'present'],
            'in_column' => ['card_in_column' => $this->rConditionSlug],
            'not_in_column' => ['not_in_column' => $this->rConditionSlug],
            'intent' => ['intent' => trim($this->rIntent)],
            default => null,
        };
    }

    /** Rotulo pt-BR da condicao de uma regra (lista). */
    public function conditionLabel(BoardRule $rule, Board $board): string
    {
        [$tipo, $slug, $intent] = $this->parseCondition((array) $rule->conditions);
        $nomeCol = $slug !== '' ? ($board->columns->firstWhere('slug', $slug)?->name ?? $slug) : '';

        return match ($tipo) {
            'card_absent' => 'card ainda nao existe',
            'card_present' => 'card ja existe',
            'in_column' => 'card em "' . $nomeCol . '"',
            'not_in_column' => 'card fora de "' . $nomeCol . '"',
            'intent' => 'IA respondeu intent "' . $intent . '"',
            default => 'sempre',
        };
    }

    // ---- render ------------------------------------------------------------------------

    public function render()
    {
        $board = Board::query()->where('is_default', true)->with(['columns'])->first()
            ?? app(BoardProvisioner::class)->ensureDefaultBoard(app(AccountContext::class)->id());

        $busca = mb_strtolower(trim($this->search), 'UTF-8');
        $cards = Card::query()
            ->where('board_id', $board->id)
            ->with(['contact:id,push_name,remote_jid', 'contact.tags:id,name,color'])
            ->orderByDesc('last_interaction_at')
            ->limit(500)
            ->get()
            ->filter(function (Card $c) use ($busca) {
                // T-1: filtro por tag junto da busca.
                if ($this->filterTagId !== null && ! $c->contact?->tags->contains('id', $this->filterTagId)) {
                    return false;
                }
                if ($busca === '') {
                    return true;
                }
                $alvo = mb_strtolower(($c->contact?->push_name ?? '') . ' ' . ($c->contact?->remote_jid ?? ''), 'UTF-8');

                return str_contains($alvo, $busca);
            })
            ->groupBy('column_id');

        // Historico do card (modal): nomes de coluna resolvidos (ids sem FK dura).
        $history = null;
        $colNames = $board->columns->pluck('name', 'id');
        if ($this->historyCardId) {
            $card = Card::query()->where('board_id', $board->id)->with('contact')->find($this->historyCardId);
            $history = $card ? [
                'card' => $card,
                'transitions' => CardTransition::query()->where('card_id', $card->id)->latest('id')->limit(30)->get(),
            ] : null;
        }

        $rules = $this->showRules ? $board->rules()->with(['toColumn', 'tag'])->get() : collect();

        return view('livewire.kanban', [
            'allTags' => \App\Models\Tag::query()->orderBy('name')->get(),
            'board' => $board,
            'columns' => $board->columns()->get(),
            'cardsByColumn' => $cards,
            'history' => $history,
            'colLabels' => $colNames,
            'rules' => $rules,
        ]);
    }
}
