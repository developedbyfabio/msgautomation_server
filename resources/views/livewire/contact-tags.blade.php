<div class="space-y-2">
    <label class="flex items-center gap-1 text-xs font-medium">
        Tags
        <x-info-tip text="Segmentacao: tags nao enviam nada. Sao aplicadas na mao aqui ou automaticamente por regras de movimento do Kanban, e usadas como escopo de regras/fluxos (e das proativas, no futuro)." />
    </label>

    <div class="flex flex-wrap items-center gap-1.5">
        @forelse ($atuais as $tag)
            @php
                $origem = match ($tag->pivot->origin) {
                    'board_rule' => 'regra do Kanban #' . $tag->pivot->origin_ref,
                    'ai_intent' => 'intent: ' . $tag->pivot->origin_ref,
                    default => 'manual',
                };
            @endphp
            <x-tag-chip :color="$tag->color" title="Origem: {{ $origem }} · {{ $tag->pivot->created_at?->paraExibicao()->format('d/m/Y H:i') }}" wire:key="ct-{{ $tag->id }}">
                {{ $tag->name }}
                <button type="button" wire:click="removeTag({{ $tag->id }})" class="opacity-60 hover:opacity-100" aria-label="Remover tag {{ $tag->name }}">
                    <flux:icon icon="x-mark" variant="micro" class="size-3" />
                </button>
            </x-tag-chip>
        @empty
            <span class="text-xs text-zinc-400">Sem tags.</span>
        @endforelse
    </div>

    <div class="relative">
        <form wire:submit="addTag" class="flex items-center gap-2">
            <input type="text" wire:model.live.debounce.250ms="tagInput" placeholder="Adicionar tag..." maxlength="40"
                class="min-w-0 flex-1 rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-800">
            <button type="submit" class="shrink-0 rounded-lg border border-zinc-300 px-2.5 py-1.5 text-xs font-medium hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">
                <flux:icon icon="plus" variant="micro" />
            </button>
        </form>
        @if ($sugestoes->isNotEmpty())
            <div class="absolute z-10 mt-1 w-full rounded-lg border border-zinc-200 bg-white p-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-900">
                @foreach ($sugestoes as $s)
                    <button type="button" wire:click="attachExisting({{ $s->id }})" wire:key="sug-{{ $s->id }}"
                        class="flex w-full items-center gap-2 rounded px-2 py-1 text-left text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800">
                        <x-tag-chip :color="$s->color" small>{{ $s->name }}</x-tag-chip>
                    </button>
                @endforeach
            </div>
        @endif
    </div>
</div>
