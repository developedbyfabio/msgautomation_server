<div class="h-full overflow-y-auto">
    <div class="mx-auto max-w-4xl p-6 space-y-4">
        <div class="flex items-center justify-between gap-3">
            <h1 class="text-xl font-semibold">Contatos / Agenda</h1>
            <div class="relative w-64">
                <span class="pointer-events-none absolute inset-y-0 left-2 flex items-center text-zinc-400">
                    <flux:icon icon="magnifying-glass" variant="micro" />
                </span>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Buscar nome ou numero..."
                    class="w-full rounded-lg border border-zinc-300 bg-white py-2 pl-8 pr-3 text-sm dark:border-zinc-700 dark:bg-zinc-800">
            </div>
        </div>

        <p class="text-sm text-zinc-500">
            Agenda auto-populada. <strong>on</strong> = responde (sob allowlist); <strong>off</strong> = nunca;
            <strong>default</strong> = segue a politica.
        </p>

        <div class="rounded-xl border border-zinc-200 bg-white divide-y divide-zinc-100 dark:border-zinc-800 dark:bg-zinc-900 dark:divide-zinc-800">
            @forelse ($contacts as $c)
                <div class="flex items-center gap-3 p-3" wire:key="c-{{ $c->id }}">
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-zinc-200 text-sm font-medium text-zinc-600 dark:bg-zinc-700 dark:text-zinc-200">
                        {{ mb_strtoupper(mb_substr($c->push_name ?: '?', 0, 1)) }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="truncate font-medium">{{ $c->push_name ?: 'Sem nome' }}</div>
                        <div class="truncate text-xs text-zinc-500">{{ $c->remote_jid }}</div>
                        @if ($c->notes)
                            <div class="truncate text-xs text-zinc-400">{{ $c->notes }}</div>
                        @endif
                    </div>

                    <span @class([
                        'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
                        'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' => $c->auto_reply_mode === 'on',
                        'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' => $c->auto_reply_mode === 'off',
                        'bg-zinc-200 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' => ! in_array($c->auto_reply_mode, ['on', 'off'], true),
                    ])>
                        @if ($c->auto_reply_mode === 'on') <span class="size-1.5 rounded-full bg-emerald-500"></span> @endif
                        {{ $c->auto_reply_mode }}
                    </span>

                    <flux:dropdown position="bottom" align="end">
                        <button type="button" class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" aria-label="Acoes">
                            <flux:icon icon="ellipsis-vertical" variant="micro" />
                        </button>
                        <flux:menu>
                            <flux:menu.item wire:click="setMode({{ $c->id }}, 'on')" icon="check-circle">Aprovar (on)</flux:menu.item>
                            <flux:menu.item wire:click="setMode({{ $c->id }}, 'default')" icon="minus-circle">Default</flux:menu.item>
                            <flux:menu.item wire:click="startEdit({{ $c->id }})" icon="pencil-square">Editar</flux:menu.item>
                            <flux:menu.separator />
                            <flux:menu.item wire:click="confirmMute({{ $c->id }})" icon="no-symbol" variant="danger">Silenciar (off)</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>
            @empty
                <div class="flex flex-col items-center gap-2 p-10 text-center text-zinc-400">
                    <flux:icon icon="users" class="size-8" />
                    <p class="text-sm">Nenhum contato ainda. Eles aparecem conforme mensagens chegam.</p>
                </div>
            @endforelse
        </div>
    </div>

    {{-- MODAL: editar contato --}}
    @if ($editing)
        <x-modal wireClose="cancelEdit" title="Editar contato">
            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-medium mb-1">Nome</label>
                    <input type="text" wire:model="editName" data-autofocus class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Notas</label>
                    <textarea wire:model="editNotes" rows="3" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"></textarea>
                </div>
                <div class="flex justify-end gap-2 pt-1">
                    <button type="button" wire:click="cancelEdit" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                    <button type="button" wire:click="saveEdit" class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-zinc-900">
                        <flux:icon icon="check" variant="micro" /> Salvar
                    </button>
                </div>
            </div>
        </x-modal>
    @endif

    {{-- MODAL: confirmar silenciar --}}
    @if ($muting)
        <x-modal wireClose="cancelMute" title="Silenciar contato">
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                Silenciar <strong>{{ $muting->push_name ?: $muting->remote_jid }}</strong>? O robo nunca vai
                auto-responder este contato (off). Voce ainda pode mandar mensagem manual.
            </p>
            <div class="flex justify-end gap-2 pt-4">
                <button type="button" wire:click="cancelMute" data-autofocus class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                <button type="button" wire:click="muteConfirmed" class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                    <flux:icon icon="no-symbol" variant="micro" /> Silenciar
                </button>
            </div>
        </x-modal>
    @endif
</div>
