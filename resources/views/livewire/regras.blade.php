<div class="h-full overflow-y-auto">
    <div class="mx-auto max-w-4xl p-6 space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">Regras (automacoes)</h1>
            <button type="button" wire:click="novo"
                class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-3 py-2 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
                <flux:icon icon="plus" variant="micro" /> Nova regra
            </button>
        </div>

        <p class="text-sm text-zinc-500">
            Gatilho -> resposta fixa. <strong>contains</strong> casa palavra inteira. Acento e maiusculas
            sao ignorados. Primeira regra (de cima) que casa vence.
        </p>

        <div class="rounded-xl border border-zinc-200 bg-white divide-y divide-zinc-100 dark:border-zinc-800 dark:bg-zinc-900 dark:divide-zinc-800">
            @forelse ($rules as $rule)
                <div class="flex items-center gap-3 p-3" wire:key="r-{{ $rule->id }}">
                    <div class="flex flex-col text-zinc-400">
                        <button type="button" wire:click="move({{ $rule->id }}, 'up')" class="hover:text-zinc-700 dark:hover:text-zinc-200" aria-label="Subir">
                            <flux:icon icon="chevron-up" variant="micro" />
                        </button>
                        <button type="button" wire:click="move({{ $rule->id }}, 'down')" class="hover:text-zinc-700 dark:hover:text-zinc-200" aria-label="Descer">
                            <flux:icon icon="chevron-down" variant="micro" />
                        </button>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="rounded bg-zinc-100 px-1.5 py-0.5 text-xs font-mono dark:bg-zinc-800">{{ $rule->match_type }}</span>
                            <span class="truncate font-medium">{{ $rule->match_value }}</span>
                        </div>
                        <div class="truncate text-sm text-zinc-500">&rarr; {{ $rule->response_text }}</div>
                    </div>

                    <span @class([
                        'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
                        'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' => $rule->enabled,
                        'bg-zinc-200 text-zinc-500 dark:bg-zinc-800' => ! $rule->enabled,
                    ])>{{ $rule->enabled ? 'ativa' : 'inativa' }}</span>

                    <flux:dropdown position="bottom" align="end">
                        <button type="button" class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" aria-label="Acoes">
                            <flux:icon icon="ellipsis-vertical" variant="micro" />
                        </button>
                        <flux:menu>
                            <flux:menu.item wire:click="edit({{ $rule->id }})" icon="pencil-square">Editar</flux:menu.item>
                            <flux:menu.item wire:click="toggle({{ $rule->id }})" icon="{{ $rule->enabled ? 'pause' : 'play' }}">
                                {{ $rule->enabled ? 'Desativar' : 'Ativar' }}
                            </flux:menu.item>
                            <flux:menu.separator />
                            <flux:menu.item wire:click="confirmDelete({{ $rule->id }})" icon="trash" variant="danger">Excluir</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>
            @empty
                <div class="flex flex-col items-center gap-2 p-10 text-center text-zinc-400">
                    <flux:icon icon="bolt" class="size-8" />
                    <p class="text-sm">Nenhuma regra criada. Crie a primeira automacao.</p>
                </div>
            @endforelse
        </div>
    </div>

    {{-- MODAL: criar/editar regra --}}
    @if ($showForm)
        <x-modal wireClose="closeForm" title="{{ $editingId ? 'Editar regra' : 'Nova regra' }}">
            <form wire:submit="save" class="space-y-3">
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-medium mb-1">Tipo</label>
                        <select wire:model="match_type" data-autofocus class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                            <option value="contains">contains</option>
                            <option value="exact">exact</option>
                            <option value="starts_with">starts_with</option>
                        </select>
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs font-medium mb-1">Gatilho</label>
                        <input type="text" wire:model="match_value" placeholder="ex.: horario"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                        @error('match_value') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Resposta</label>
                    <textarea wire:model="response_text" rows="3" placeholder="ex.: Atendo das 8h as 18h"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"></textarea>
                    @error('response_text') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>
                <label class="inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="enabled" class="rounded border-zinc-300 dark:border-zinc-700"> Habilitada
                </label>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" wire:click="closeForm" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                    <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-zinc-900">
                        <span wire:loading.remove wire:target="save"><flux:icon icon="check" variant="micro" /></span>
                        Salvar
                    </button>
                </div>
            </form>
        </x-modal>
    @endif

    {{-- MODAL: confirmar exclusao --}}
    @if ($deleting)
        <x-modal wireClose="cancelDelete" title="Excluir regra">
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                Apagar a regra <strong>{{ $deleting->match_type }} "{{ $deleting->match_value }}"</strong>? Esta acao nao pode ser desfeita.
            </p>
            <div class="flex justify-end gap-2 pt-4">
                <button type="button" wire:click="cancelDelete" data-autofocus class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                <button type="button" wire:click="deleteConfirmed" class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                    <flux:icon icon="trash" variant="micro" /> Excluir
                </button>
            </div>
        </x-modal>
    @endif
</div>
