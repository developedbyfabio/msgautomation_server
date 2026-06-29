<div class="flex h-full" wire:poll.5s>
    {{-- LISTA DE CONVERSAS --}}
    <aside class="w-80 shrink-0 border-r border-zinc-200 bg-white overflow-y-auto dark:border-zinc-800 dark:bg-zinc-900">
        <div class="p-3 text-xs font-semibold uppercase tracking-wide text-zinc-400">Conversas</div>
        @forelse ($conversations as $conv)
            <button type="button" wire:click="select('{{ $conv['jid'] }}')" wire:key="conv-{{ $conv['jid'] }}"
                @class([
                    'flex w-full items-center gap-3 border-b border-zinc-100 px-3 py-3 text-left transition dark:border-zinc-800',
                    'bg-zinc-100 dark:bg-zinc-800' => $selectedJid === $conv['jid'],
                    'hover:bg-zinc-50 dark:hover:bg-zinc-800/50' => $selectedJid !== $conv['jid'],
                ])>
                <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-zinc-200 text-sm font-medium text-zinc-600 dark:bg-zinc-700 dark:text-zinc-200">
                    {{ mb_strtoupper(mb_substr($conv['name'], 0, 1)) }}
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-1.5">
                        <span class="truncate font-medium">{{ $conv['name'] }}</span>
                        @if ($conv['is_group'])
                            <span class="rounded bg-zinc-200 px-1 text-[10px] text-zinc-500 dark:bg-zinc-700">grupo</span>
                        @elseif ($conv['mode'] === 'on')
                            <span class="size-2 shrink-0 rounded-full bg-emerald-500" title="auto-resposta ON"></span>
                        @elseif ($conv['mode'] === 'off')
                            <span class="size-2 shrink-0 rounded-full bg-red-400" title="auto-resposta OFF"></span>
                        @endif
                    </div>
                    <div class="truncate text-sm text-zinc-500">{{ $conv['text'] }}</div>
                </div>
                <div class="shrink-0 text-[11px] text-zinc-400">{{ optional($conv['at'])->format('d/m H:i') }}</div>
            </button>
        @empty
            <div class="p-8 text-center text-sm text-zinc-500">Nenhuma conversa ainda.</div>
        @endforelse
    </aside>

    {{-- THREAD --}}
    <section class="flex min-w-0 flex-1 flex-col bg-zinc-50 dark:bg-zinc-950">
        @if (! $selectedJid)
            <div class="flex h-full items-center justify-center text-sm text-zinc-400">
                Selecione uma conversa.
            </div>
        @else
            {{-- header --}}
            <div class="flex shrink-0 items-center gap-3 border-b border-zinc-200 bg-white px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="min-w-0 flex-1">
                    <div class="truncate font-medium">{{ $selectedContact?->push_name ?: \Illuminate\Support\Str::before($selectedJid, '@') }}</div>
                    <div class="truncate text-xs text-zinc-500">{{ $selectedJid }}</div>
                </div>
                @unless ($isGroup)
                    <div class="flex items-center gap-2 text-xs">
                        <span class="text-zinc-400">auto:</span>
                        <span @class([
                            'rounded-full px-2 py-0.5 font-medium',
                            'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' => ($selectedContact?->auto_reply_mode) === 'on',
                            'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' => ($selectedContact?->auto_reply_mode) === 'off',
                            'bg-zinc-200 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' => ! in_array($selectedContact?->auto_reply_mode, ['on','off'], true),
                        ])>{{ $selectedContact?->auto_reply_mode ?? 'default' }}</span>
                        <button type="button" wire:click="approveContact" class="rounded-lg border border-emerald-300 px-2 py-1 text-emerald-700 hover:bg-emerald-50 dark:border-emerald-800 dark:text-emerald-300 dark:hover:bg-emerald-950">Aprovar</button>
                        <button type="button" wire:click="muteContact" class="rounded-lg border border-zinc-300 px-2 py-1 hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">Silenciar</button>
                    </div>
                @endunless
            </div>

            {{-- mensagens --}}
            <div class="flex-1 space-y-2 overflow-y-auto p-4">
                @forelse ($thread as $msg)
                    @php
                        $isIn = $msg['kind'] === 'in';
                        $label = match ($msg['kind']) {
                            'out_bot' => 'robo',
                            'out_manual' => 'manual',
                            'out_phone' => 'celular',
                            default => null,
                        };
                    @endphp
                    <div @class(['flex', 'justify-start' => $isIn, 'justify-end' => ! $isIn])>
                        <div @class([
                            'max-w-[75%] rounded-2xl px-3 py-2 text-sm shadow-sm',
                            'bg-white text-zinc-800 dark:bg-zinc-800 dark:text-zinc-100' => $isIn,
                            'bg-sky-100 text-sky-900 dark:bg-sky-900 dark:text-sky-50' => $msg['kind'] === 'out_phone',
                            'bg-zinc-800 text-white dark:bg-zinc-700' => $msg['kind'] === 'out_manual',
                            'bg-emerald-600 text-white' => $msg['kind'] === 'out_bot',
                        ])>
                            <div class="whitespace-pre-wrap break-words">{{ $msg['text'] }}</div>
                            <div class="mt-0.5 flex items-center gap-1 text-[10px] opacity-70">
                                @if ($label)<span class="uppercase">{{ $label }}</span>@endif
                                <span>{{ optional($msg['at'])->format('d/m H:i') }}</span>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="text-center text-sm text-zinc-400">Sem mensagens nesta conversa.</div>
                @endforelse
            </div>

            {{-- envio manual --}}
            <div class="shrink-0 border-t border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
                @if ($sendStatus)
                    <div class="mb-2 rounded-lg bg-amber-100 px-3 py-1.5 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-300">{{ $sendStatus }}</div>
                @endif
                @if ($isGroup)
                    <div class="text-center text-xs text-zinc-400">Envio manual desabilitado para grupos.</div>
                @else
                    <form wire:submit="sendManual" class="flex items-end gap-2">
                        <textarea wire:model="body" rows="1" placeholder="Mensagem manual..."
                            class="max-h-32 flex-1 resize-none rounded-2xl border border-zinc-300 bg-white px-4 py-2 text-sm focus:outline-none dark:border-zinc-700 dark:bg-zinc-800"></textarea>
                        <button type="submit" class="rounded-full bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">Enviar</button>
                    </form>
                    <p class="mt-1 text-[11px] text-zinc-400">Envio manual envia de verdade (respeita tetos, ignora o kill switch).</p>
                @endif
            </div>
        @endif
    </section>
</div>
