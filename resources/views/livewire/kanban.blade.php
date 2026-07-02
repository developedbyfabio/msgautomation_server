<div class="flex h-full flex-col" wire:poll.15s>
    <div class="shrink-0 space-y-3 px-6 pt-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-1">
                <h1 class="text-xl font-semibold">Kanban (funil de conversas)</h1>
                <x-info-tip text="Cada conversa individual e um card que se move sozinho pelos eventos do robo (regras de movimento) ou pela sua mao. O Kanban so OBSERVA: nunca envia mensagem nem muda o comportamento do robo." />
            </div>
            <div class="flex items-center gap-2">
                <div class="relative w-56">
                    <span class="pointer-events-none absolute inset-y-0 left-2 flex items-center text-zinc-400">
                        <flux:icon icon="magnifying-glass" variant="micro" />
                    </span>
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Buscar contato..."
                        class="w-full rounded-lg border border-zinc-300 bg-white py-2 pl-8 pr-3 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                </div>
                {{-- T-1: filtro por tag --}}
                <select wire:model.live="filterTagId" class="rounded-lg border border-zinc-300 bg-white px-2 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    <option value="">Todas as tags</option>
                    @foreach ($allTags as $t)
                        <option value="{{ $t->id }}">{{ $t->name }}</option>
                    @endforeach
                </select>
                <button type="button" wire:click="openRules"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 px-3 py-2 text-sm font-medium hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">
                    <flux:icon icon="bolt" variant="micro" /> Regras de movimento
                </button>
                <button type="button" wire:click="openColumns"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 px-3 py-2 text-sm font-medium hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">
                    <flux:icon icon="view-columns" variant="micro" /> Gerenciar colunas
                </button>
            </div>
        </div>
    </div>

    {{-- BOARD: colunas lado a lado, scroll horizontal; corpo de cada coluna com scroll proprio --}}
    <div class="min-h-0 flex-1 overflow-x-auto px-6 pb-6 pt-4">
        <div class="flex h-full items-stretch gap-3">
            @foreach ($columns as $col)
                @php $cards = $cardsByColumn->get($col->id, collect()); @endphp
                <div class="flex h-full w-72 shrink-0 flex-col rounded-xl border border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900/60" wire:key="col-{{ $col->id }}">
                    {{-- header FIXO da coluna --}}
                    <div class="flex shrink-0 items-center justify-between gap-2 border-b border-zinc-200 px-3 py-2 dark:border-zinc-800">
                        <span class="truncate text-sm font-semibold">{{ $col->name }}</span>
                        <span class="shrink-0 rounded-full bg-zinc-200 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">{{ $cards->count() }}</span>
                    </div>
                    {{-- corpo com scroll interno --}}
                    <div class="min-h-0 flex-1 space-y-2 overflow-y-auto p-2">
                        @forelse ($cards as $card)
                            @php $nome = $card->contact?->push_name ?: \Illuminate\Support\Str::before((string) $card->contact?->remote_jid, '@'); @endphp
                            <div class="rounded-lg border border-zinc-200 bg-white p-2.5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900" wire:key="card-{{ $card->id }}">
                                <div class="flex items-start gap-2">
                                    <a href="{{ route('conversas', ['jid' => $card->contact?->remote_jid]) }}" wire:navigate class="min-w-0 flex-1 hover:opacity-80" title="Abrir conversa">
                                        <div class="truncate text-sm font-medium">{{ $nome }}</div>
                                        <div class="mt-0.5 flex items-center gap-1.5 text-[11px] text-zinc-400">
                                            @if ($card->last_direction === 'in')
                                                <flux:icon icon="arrow-down-left" variant="micro" class="size-3 text-emerald-500" title="Ultima: contato falou" />
                                            @elseif ($card->last_direction === 'out')
                                                <flux:icon icon="arrow-up-right" variant="micro" class="size-3 text-sky-500" title="Ultima: voce/robo respondeu" />
                                            @endif
                                            <span>{{ $card->last_interaction_at?->diffForHumans() ?? '-' }}</span>
                                        </div>
                                        @if ($card->contact?->tags->isNotEmpty())
                                            <div class="mt-1 flex flex-wrap gap-1">
                                                @foreach ($card->contact->tags->take(3) as $t)
                                                    <x-tag-chip :color="$t->color" small wire:key="kt-{{ $card->id }}-{{ $t->id }}">{{ $t->name }}</x-tag-chip>
                                                @endforeach
                                                @if ($card->contact->tags->count() > 3)
                                                    <span class="text-[10px] text-zinc-400">+{{ $card->contact->tags->count() - 3 }}</span>
                                                @endif
                                            </div>
                                        @endif
                                    </a>
                                    <flux:dropdown position="bottom" align="end">
                                        <button type="button" class="rounded p-1 text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800" aria-label="Acoes do card">
                                            <flux:icon icon="ellipsis-vertical" variant="micro" />
                                        </button>
                                        <flux:menu>
                                            @foreach ($columns as $destino)
                                                @if ($destino->id !== $col->id)
                                                    <flux:menu.item wire:click="moveCard({{ $card->id }}, {{ $destino->id }})" icon="arrow-right">
                                                        Mover: {{ $destino->name }}
                                                    </flux:menu.item>
                                                @endif
                                            @endforeach
                                            <flux:menu.separator />
                                            <flux:menu.item wire:click="showHistory({{ $card->id }})" icon="clock">Historico</flux:menu.item>
                                            <flux:menu.item href="{{ route('conversas', ['jid' => $card->contact?->remote_jid]) }}" wire:navigate icon="chat-bubble-left-right">Abrir conversa</flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </div>
                            </div>
                        @empty
                            <p class="px-2 py-6 text-center text-xs text-zinc-400">{{ $search !== '' ? 'Nada aqui na busca.' : 'Sem cards.' }}</p>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- MODAL: historico do card --}}
    @if ($history)
        <x-modal wireClose="closeHistory" title="Historico — {{ $history['card']->contact?->push_name ?: \Illuminate\Support\Str::before((string) $history['card']->contact?->remote_jid, '@') }}">
            <ul class="divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                @forelse ($history['transitions'] as $t)
                    <li class="flex items-center justify-between gap-2 py-2" wire:key="t-{{ $t->id }}">
                        <span class="min-w-0">
                            <span class="text-zinc-500">{{ $t->from_column_id ? ($colLabels[$t->from_column_id] ?? 'coluna removida') : 'criado' }}</span>
                            <flux:icon icon="arrow-right" variant="micro" class="inline size-3 text-zinc-400" />
                            <span class="font-medium">{{ $colLabels[$t->to_column_id] ?? 'coluna removida' }}</span>
                            <span @class([
                                'ml-1 rounded px-1.5 py-0.5 text-[10px]',
                                'bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300' => $t->cause === 'regra',
                                'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300' => $t->cause === 'manual',
                                'bg-zinc-100 text-zinc-500 dark:bg-zinc-800' => ! in_array($t->cause, ['regra', 'manual'], true),
                            ])>{{ $t->cause }}@if ($t->event_type) · {{ $t->event_type }}@endif</span>
                        </span>
                        <span class="shrink-0 text-xs text-zinc-400">{{ $t->created_at->paraExibicao()->format('d/m H:i') }}</span>
                    </li>
                @empty
                    <li class="py-4 text-center text-xs text-zinc-400">Sem movimentos registrados.</li>
                @endforelse
            </ul>
        </x-modal>
    @endif

    {{-- MODAL: gerenciar colunas --}}
    @if ($showColumns)
        <x-modal wireClose="closeColumns" title="Gerenciar colunas" maxWidth="lg">
            <div class="space-y-3">
                <p class="text-xs text-zinc-500">
                    Renomear muda so o nome exibido — as regras de movimento continuam funcionando
                    (referencia interna estavel). Colunas padrao nao podem ser excluidas.
                </p>
                <div class="space-y-2">
                    @foreach ($columns as $col)
                        <div class="flex items-center gap-2" wire:key="coledit-{{ $col->id }}">
                            <div class="flex shrink-0 flex-col">
                                <button type="button" wire:click="moveColumn({{ $col->id }}, 'up')" class="text-zinc-400 hover:text-zinc-600 disabled:opacity-30" @disabled($loop->first) aria-label="Subir">
                                    <flux:icon icon="chevron-up" variant="micro" class="size-3.5" />
                                </button>
                                <button type="button" wire:click="moveColumn({{ $col->id }}, 'down')" class="text-zinc-400 hover:text-zinc-600 disabled:opacity-30" @disabled($loop->last) aria-label="Descer">
                                    <flux:icon icon="chevron-down" variant="micro" class="size-3.5" />
                                </button>
                            </div>
                            <input type="text" wire:model="colNames.{{ $col->id }}"
                                class="min-w-0 flex-1 rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                            @if (array_key_exists($col->slug, \App\Kanban\BoardProvisioner::DEFAULT_COLUMNS))
                                <span class="shrink-0 rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] text-zinc-500 dark:bg-zinc-800" title="Coluna padrao: as regras default dependem dela. Renomear pode.">padrao</span>
                            @else
                                <button type="button" wire:click="deleteColumn({{ $col->id }})" class="shrink-0 text-zinc-400 hover:text-red-500" aria-label="Excluir coluna">
                                    <flux:icon icon="trash" variant="micro" />
                                </button>
                            @endif
                            @error('colNames.' . $col->id) <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>
                <div class="flex items-center gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                    <input type="text" wire:model="newColName" placeholder="Nova coluna (ex.: Orcamento)"
                        class="min-w-0 flex-1 rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    <button type="button" wire:click="addColumn" class="inline-flex items-center gap-1 rounded-lg border border-zinc-300 px-3 py-1.5 text-sm font-medium hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">
                        <flux:icon icon="plus" variant="micro" /> Adicionar
                    </button>
                </div>
                @error('newColName') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
            </div>
            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="closeColumns" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                    <button type="button" wire:click="saveColumns" class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-zinc-900">
                        <flux:icon icon="check" variant="micro" /> Salvar
                    </button>
                </div>
            </x-slot:footer>
        </x-modal>
    @endif

    {{-- MODAL: regras de movimento (lista) --}}
    @if ($showRules && ! $showRuleForm && ! $confirmingDefaultRuleId)
        <x-modal wireClose="closeRules" title="Regras de movimento" maxWidth="xl">
            <div class="space-y-3">
                <p class="flex items-start gap-1 text-xs text-zinc-500">
                    <span>
                        Avaliadas de cima pra baixo: <strong>a primeira que casa move o card; as demais nao rodam</strong>
                        (first-match). Mudancas valem so pra eventos futuros — nada reprocessa o historico.
                    </span>
                    <x-info-tip text="Cada evento do robo (mensagem recebida, resposta enviada, envio manual, fluxo, IA) passa pela lista NA ORDEM. A primeira regra ativa cujo evento e condicao casam move (ou cria) o card e o processamento para." />
                </p>
                <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($rules as $rule)
                        <li class="flex items-center gap-2 py-2 text-sm" wire:key="rule-{{ $rule->id }}">
                            <div class="flex shrink-0 flex-col">
                                <button type="button" wire:click="moveRule({{ $rule->id }}, 'up')" class="text-zinc-400 hover:text-zinc-600 disabled:opacity-30" @disabled($loop->first) aria-label="Subir">
                                    <flux:icon icon="chevron-up" variant="micro" class="size-3.5" />
                                </button>
                                <button type="button" wire:click="moveRule({{ $rule->id }}, 'down')" class="text-zinc-400 hover:text-zinc-600 disabled:opacity-30" @disabled($loop->last) aria-label="Descer">
                                    <flux:icon icon="chevron-down" variant="micro" class="size-3.5" />
                                </button>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="truncate">
                                    <span class="font-medium">{{ \Illuminate\Support\Str::before(\App\Livewire\Kanban::EVENTOS[$rule->event_type] ?? $rule->event_type, ' — ') }}</span>
                                    <span class="text-zinc-400">+ {{ $this->conditionLabel($rule, $board) }}</span>
                                    <flux:icon icon="arrow-right" variant="micro" class="inline size-3 text-zinc-400" />
                                    @if (($rule->action_type ?: 'move_column') === 'move_column')
                                        <span class="font-medium">{{ $rule->toColumn?->name ?? '?' }}</span>
                                    @else
                                        <span class="font-medium">{{ $rule->action_type === 'add_tag' ? '+tag' : '-tag' }} "{{ $rule->tag?->name ?? '?' }}"</span>
                                    @endif
                                </div>
                                <div class="mt-0.5 flex items-center gap-1.5 text-[10px] text-zinc-400">
                                    @if ($rule->is_default)<span class="rounded bg-zinc-100 px-1.5 py-0.5 dark:bg-zinc-800">padrao</span>@endif
                                    <span @class([
                                        'rounded px-1.5 py-0.5',
                                        'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' => $rule->active,
                                        'bg-zinc-200 text-zinc-500 dark:bg-zinc-800' => ! $rule->active,
                                    ])>{{ $rule->active ? 'ativa' : 'inativa' }}</span>
                                </div>
                            </div>
                            <button type="button" wire:click="toggleRule({{ $rule->id }})"
                                class="shrink-0 rounded-lg border border-zinc-300 px-2 py-1 text-xs hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">
                                {{ $rule->active ? 'Desativar' : 'Ativar' }}
                            </button>
                            <button type="button" wire:click="startRuleEdit({{ $rule->id }})"
                                class="shrink-0 rounded-lg border border-zinc-300 px-2 py-1 text-xs hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">
                                Editar
                            </button>
                        </li>
                    @empty
                        <li class="py-4 text-center text-xs text-zinc-400">Nenhuma regra.</li>
                    @endforelse
                </ul>
            </div>
            <x-slot:footer>
                <div class="flex justify-between gap-2">
                    <button type="button" wire:click="startRuleCreate" class="inline-flex items-center gap-1 rounded-lg border border-zinc-300 px-3 py-2 text-sm font-medium hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">
                        <flux:icon icon="plus" variant="micro" /> Nova regra
                    </button>
                    <button type="button" wire:click="closeRules" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Fechar</button>
                </div>
            </x-slot:footer>
        </x-modal>
    @endif

    {{-- MODAL: confirmar mexer em regra DEFAULT --}}
    @if ($confirmingDefaultRuleId)
        <x-modal wireClose="cancelDefaultAction" title="{{ $confirmingDefaultAction === 'toggle' ? 'Desativar regra padrao?' : 'Editar regra padrao?' }}">
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                Esta e uma <strong>regra padrao</strong> do movimento automatico
                (ex.: criar card em "Novo", reabrir de "Resolvido", mover pra "Em atendimento").
                {{ $confirmingDefaultAction === 'toggle' ? 'Desativa-la para o movimento automatico correspondente' : 'Editar muda o movimento automatico' }}
                — vale so pra eventos futuros e da pra voltar atras aqui.
            </p>
            <div class="flex justify-end gap-2 pt-4">
                <button type="button" wire:click="cancelDefaultAction" data-autofocus class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                <button type="button" wire:click="confirmDefaultAction" class="inline-flex items-center gap-1.5 rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700">
                    <flux:icon icon="check" variant="micro" /> {{ $confirmingDefaultAction === 'toggle' ? 'Desativar' : 'Continuar' }}
                </button>
            </div>
        </x-modal>
    @endif

    {{-- MODAL: criar/editar regra --}}
    @if ($showRuleForm)
        <x-modal wireClose="cancelRuleForm" title="{{ $editingRuleId ? 'Editar regra de movimento' : 'Nova regra de movimento' }}" maxWidth="lg">
            <div class="space-y-3">
                <div>
                    <label class="mb-1 block text-xs font-medium">Quando acontecer (evento)</label>
                    <select wire:model.live="rEvent" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                        @foreach (\App\Livewire\Kanban::EVENTOS as $valor => $rotulo)
                            <option value="{{ $valor }}">{{ $rotulo }}</option>
                        @endforeach
                    </select>
                    @error('rEvent') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 flex items-center gap-1 text-xs font-medium">
                        Acao
                        <x-info-tip text="Mover pra coluna segue FIRST-MATCH (so a primeira regra de coluna que casa move). Acoes de tag sao CUMULATIVAS: todas as que casam aplicam. Tags nao enviam nada — segmentam." />
                    </label>
                    <select wire:model.live="rAction" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                        @foreach (\App\Livewire\Kanban::ACOES as $valor => $rotulo)
                            <option value="{{ $valor }}">{{ $rotulo }}</option>
                        @endforeach
                    </select>
                    @error('rAction') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Condicao</label>
                    <select wire:model.live="rCondition" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                        @foreach (\App\Livewire\Kanban::CONDICOES as $valor => $rotulo)
                            <option value="{{ $valor }}">{{ $rotulo }}</option>
                        @endforeach
                    </select>
                    @if (in_array($rCondition, ['in_column', 'not_in_column'], true))
                        <select wire:model="rConditionSlug" class="mt-2 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                            <option value="">Escolha a coluna...</option>
                            @foreach ($columns as $col)
                                <option value="{{ $col->slug }}">{{ $col->name }}</option>
                            @endforeach
                        </select>
                        @error('rConditionSlug') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    @endif
                    @if ($rCondition === 'intent')
                        <input type="text" wire:model="rIntent" placeholder="ex.: pedir_pix"
                            class="mt-2 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                        @error('rIntent') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                        <p class="mt-1 text-[11px] text-zinc-400">Casa quando a IA RESPONDEU com esse intent (acima do limiar). Veja os intents na aba "Decisoes da IA" do /revisao.</p>
                    @endif
                </div>
                @if ($rAction === 'move_column')
                    <div>
                        <label class="mb-1 block text-xs font-medium">Mover o card para</label>
                        <select wire:model="rToColumnId" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                            <option value="">Escolha a coluna destino...</option>
                            @foreach ($columns as $col)
                                <option value="{{ $col->id }}">{{ $col->name }}</option>
                            @endforeach
                        </select>
                        @error('rToColumnId') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                @else
                    <div>
                        <label class="mb-1 block text-xs font-medium">{{ $rAction === 'add_tag' ? 'Aplicar a tag' : 'Remover a tag' }}</label>
                        <select wire:model="rTagId" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                            <option value="">Escolha a tag...</option>
                            @foreach ($allTags as $t)
                                <option value="{{ $t->id }}">{{ $t->name }}</option>
                            @endforeach
                        </select>
                        @error('rTagId') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                        <p class="mt-1 text-[11px] text-zinc-400">Sem tags ainda? Crie no painel de um contato (/contatos).</p>
                    </div>
                @endif
                <label class="inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="rActive" class="rounded border-zinc-300 dark:border-zinc-700"> Ativa
                </label>
                <p class="text-[11px] text-zinc-400">Vale so pra eventos futuros. Se o card nao existir e a condicao permitir, ele e criado direto na coluna destino.</p>
            </div>
            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="cancelRuleForm" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                    <button type="button" wire:click="saveRule" class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-zinc-900">
                        <flux:icon icon="check" variant="micro" /> Salvar
                    </button>
                </div>
            </x-slot:footer>
        </x-modal>
    @endif
</div>
