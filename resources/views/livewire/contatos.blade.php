<div class="h-full overflow-y-auto">
    <div class="mx-auto max-w-4xl p-6 space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">Contatos / Agenda</h1>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Buscar nome ou numero..."
                class="w-64 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
        </div>

        <p class="text-sm text-zinc-500">
            Agenda auto-populada pelas mensagens recebidas. <strong>on</strong> = responde (sob allowlist);
            <strong>off</strong> = nunca; <strong>default</strong> = segue a politica.
        </p>

        <div class="rounded-xl border border-zinc-200 bg-white divide-y divide-zinc-100 dark:border-zinc-800 dark:bg-zinc-900 dark:divide-zinc-800">
            @forelse ($contacts as $c)
                <div class="p-3" wire:key="c-{{ $c->id }}">
                    <div class="flex items-center gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium">{{ $c->push_name ?: 'Sem nome' }}</div>
                            <div class="truncate text-xs text-zinc-500">{{ $c->remote_jid }}</div>
                        </div>

                        <div class="inline-flex overflow-hidden rounded-lg border border-zinc-300 dark:border-zinc-700">
                            @foreach (['default' => 'Default', 'on' => 'On', 'off' => 'Off'] as $mode => $label)
                                <button type="button" wire:click="setMode({{ $c->id }}, '{{ $mode }}')"
                                    @class([
                                        'px-3 py-1 text-xs font-medium transition',
                                        'bg-emerald-500 text-white' => $c->auto_reply_mode === $mode && $mode === 'on',
                                        'bg-red-500 text-white' => $c->auto_reply_mode === $mode && $mode === 'off',
                                        'bg-zinc-700 text-white dark:bg-zinc-200 dark:text-zinc-900' => $c->auto_reply_mode === $mode && $mode === 'default',
                                        'bg-white text-zinc-600 hover:bg-zinc-100 dark:bg-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800' => $c->auto_reply_mode !== $mode,
                                    ])>{{ $label }}</button>
                            @endforeach
                        </div>

                        <button type="button" wire:click="startEdit({{ $c->id }})"
                            class="rounded-lg border border-zinc-300 px-2 py-1 text-xs hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">Editar</button>
                    </div>

                    @if ($editingId === $c->id)
                        <div class="mt-3 grid gap-2 rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800/50">
                            <input type="text" wire:model="editName" placeholder="Nome"
                                class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                            <textarea wire:model="editNotes" placeholder="Notas" rows="2"
                                class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-800"></textarea>
                            <div class="flex gap-2">
                                <button type="button" wire:click="saveEdit" class="rounded-lg bg-zinc-900 px-3 py-1.5 text-xs font-medium text-white dark:bg-white dark:text-zinc-900">Salvar</button>
                                <button type="button" wire:click="cancelEdit" class="rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700">Cancelar</button>
                            </div>
                        </div>
                    @elseif ($c->notes)
                        <div class="mt-1 text-xs text-zinc-500">{{ $c->notes }}</div>
                    @endif
                </div>
            @empty
                <div class="p-8 text-center text-sm text-zinc-500">
                    Nenhum contato ainda. Eles aparecem aqui conforme mensagens chegam.
                </div>
            @endforelse
        </div>
    </div>
</div>
