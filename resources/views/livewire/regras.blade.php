<div class="h-full overflow-y-auto">
    <div class="mx-auto max-w-4xl p-6 space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">Regras (automacoes)</h1>
            <button type="button" wire:click="novo" class="rounded-lg bg-zinc-900 px-3 py-2 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900">Nova regra</button>
        </div>

        <p class="text-sm text-zinc-500">
            Gatilho -> resposta fixa. <strong>exact</strong>: texto igual. <strong>contains</strong>: palavra inteira em qualquer lugar.
            <strong>starts_with</strong>: comeca com. Acento e maiusculas sao ignorados. Primeira regra (de cima) que casa vence.
        </p>

        @if ($showForm)
            <form wire:submit="save" class="rounded-xl border border-zinc-200 bg-white p-4 space-y-3 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-medium mb-1">Tipo</label>
                        <select wire:model="match_type" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                            <option value="contains">contains</option>
                            <option value="exact">exact</option>
                            <option value="starts_with">starts_with</option>
                        </select>
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs font-medium mb-1">Gatilho (match_value)</label>
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
                <div class="flex gap-2 pt-1">
                    <button type="submit" class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-zinc-900">Salvar regra</button>
                    <button type="button" wire:click="$set('showForm', false)" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                </div>
            </form>
        @endif

        <div class="rounded-xl border border-zinc-200 bg-white divide-y divide-zinc-100 dark:border-zinc-800 dark:bg-zinc-900 dark:divide-zinc-800">
            @forelse ($rules as $rule)
                <div class="flex items-center gap-3 p-3" wire:key="r-{{ $rule->id }}">
                    <div class="flex flex-col">
                        <button type="button" wire:click="move({{ $rule->id }}, 'up')" class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200" aria-label="Subir">&#9650;</button>
                        <button type="button" wire:click="move({{ $rule->id }}, 'down')" class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200" aria-label="Descer">&#9660;</button>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="rounded bg-zinc-100 px-1.5 py-0.5 text-xs font-mono dark:bg-zinc-800">{{ $rule->match_type }}</span>
                            <span class="truncate font-medium">{{ $rule->match_value }}</span>
                        </div>
                        <div class="truncate text-sm text-zinc-500">&rarr; {{ $rule->response_text }}</div>
                    </div>
                    <button type="button" wire:click="toggle({{ $rule->id }})"
                        @class([
                            'rounded-full px-2 py-0.5 text-xs font-medium',
                            'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' => $rule->enabled,
                            'bg-zinc-200 text-zinc-500 dark:bg-zinc-800' => ! $rule->enabled,
                        ])>{{ $rule->enabled ? 'ativa' : 'inativa' }}</button>
                    <button type="button" wire:click="edit({{ $rule->id }})" class="rounded-lg border border-zinc-300 px-2 py-1 text-xs hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">Editar</button>
                    <button type="button" wire:click="delete({{ $rule->id }})" wire:confirm="Apagar esta regra?" class="rounded-lg border border-red-300 px-2 py-1 text-xs text-red-600 hover:bg-red-50 dark:border-red-900 dark:hover:bg-red-950">Apagar</button>
                </div>
            @empty
                <div class="p-8 text-center text-sm text-zinc-500">Nenhuma regra ainda. Crie a primeira.</div>
            @endforelse
        </div>
    </div>
</div>
