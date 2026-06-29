<div class="flex h-full" wire:poll.5s>
    {{-- LISTA DE CONVERSAS --}}
    <aside class="w-80 shrink-0 border-r border-zinc-200 bg-white overflow-y-auto dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex items-center gap-2 p-3 text-xs font-semibold uppercase tracking-wide text-zinc-400">
            <flux:icon icon="chat-bubble-left-right" variant="micro" /> Conversas
        </div>
        @forelse ($conversations as $conv)
            <div wire:key="conv-{{ $conv['jid'] }}"
                @class([
                    'group flex items-center border-b border-zinc-100 dark:border-zinc-800',
                    'bg-zinc-100 dark:bg-zinc-800' => $selectedJid === $conv['jid'],
                    'hover:bg-zinc-50 dark:hover:bg-zinc-800/50' => $selectedJid !== $conv['jid'],
                ])>
                <button type="button" wire:click="select('{{ $conv['jid'] }}')" class="flex min-w-0 flex-1 items-center gap-3 px-3 py-3 text-left">
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-zinc-200 text-sm font-medium text-zinc-600 dark:bg-zinc-700 dark:text-zinc-200">
                        {{ mb_strtoupper(mb_substr($conv['name'], 0, 1)) }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-1.5">
                            <span class="truncate font-medium">{{ $conv['name'] }}</span>
                            @if ($conv['is_group'])
                                <span class="rounded bg-zinc-200 px-1 text-[10px] text-zinc-500 dark:bg-zinc-700">grupo</span>
                            @elseif ($conv['mode'] === 'on')
                                <span class="size-2 shrink-0 rounded-full bg-emerald-500" title="auto ON"></span>
                            @elseif ($conv['mode'] === 'off')
                                <span class="size-2 shrink-0 rounded-full bg-red-400" title="auto OFF"></span>
                            @endif
                        </div>
                        <div class="truncate text-sm text-zinc-500">{{ $conv['text'] }}</div>
                    </div>
                    <div class="shrink-0 text-[11px] text-zinc-400">{{ $conv['at']?->paraExibicao()->format('d/m H:i') }}</div>
                </button>
                @unless ($conv['is_group'])
                    <div class="pr-1">
                        <flux:dropdown position="bottom" align="end">
                            <button type="button" class="rounded-lg p-1.5 text-zinc-400 opacity-0 transition hover:bg-zinc-200 group-hover:opacity-100 dark:hover:bg-zinc-700" aria-label="Acoes">
                                <flux:icon icon="ellipsis-vertical" variant="micro" />
                            </button>
                            <flux:menu>
                                <flux:menu.item wire:click="approveJid('{{ $conv['jid'] }}')" icon="check-circle">Aprovar (on)</flux:menu.item>
                                <flux:menu.item wire:click="confirmMute('{{ $conv['jid'] }}')" icon="no-symbol" variant="danger">Silenciar (off)</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </div>
                @endunless
            </div>
        @empty
            <div class="flex flex-col items-center gap-2 p-10 text-center text-zinc-400">
                <flux:icon icon="chat-bubble-left-right" class="size-8" />
                <p class="text-sm">Nenhuma conversa ainda.</p>
            </div>
        @endforelse
    </aside>

    {{-- THREAD --}}
    <section class="flex min-w-0 flex-1 flex-col bg-zinc-50 dark:bg-zinc-950">
        @if (! $selectedJid)
            <div class="flex h-full flex-col items-center justify-center gap-2 text-zinc-400">
                <flux:icon icon="chat-bubble-left-right" class="size-10" />
                <p class="text-sm">Selecione uma conversa.</p>
            </div>
        @else
            <div class="flex shrink-0 items-center gap-3 border-b border-zinc-200 bg-white px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="min-w-0 flex-1">
                    <div class="truncate font-medium">{{ $selectedContact?->push_name ?: \Illuminate\Support\Str::before($selectedJid, '@') }}</div>
                    <div class="truncate text-xs text-zinc-500">{{ $selectedJid }}</div>
                </div>
                @unless ($isGroup)
                    <div class="flex items-center gap-2 text-xs">
                        <span @class([
                            'rounded-full px-2 py-0.5 font-medium',
                            'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' => ($selectedContact?->auto_reply_mode) === 'on',
                            'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' => ($selectedContact?->auto_reply_mode) === 'off',
                            'bg-zinc-200 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' => ! in_array($selectedContact?->auto_reply_mode, ['on','off'], true),
                        ])>auto: {{ $selectedContact?->auto_reply_mode ?? 'default' }}</span>
                        <button type="button" wire:click="approveJid('{{ $selectedJid }}')" class="inline-flex items-center gap-1 rounded-lg border border-emerald-300 px-2 py-1 text-emerald-700 hover:bg-emerald-50 dark:border-emerald-800 dark:text-emerald-300 dark:hover:bg-emerald-950">
                            <flux:icon icon="check-circle" variant="micro" /> Aprovar
                        </button>
                        <button type="button" wire:click="confirmMute('{{ $selectedJid }}')" class="inline-flex items-center gap-1 rounded-lg border border-zinc-300 px-2 py-1 hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">
                            <flux:icon icon="no-symbol" variant="micro" /> Silenciar
                        </button>
                    </div>
                @endunless
            </div>

            <div class="flex-1 space-y-2 overflow-y-auto p-4">
                @forelse ($thread as $msg)
                    @php
                        $isIn = $msg['kind'] === 'in';
                        $label = match ($msg['kind']) {
                            'out_bot' => 'robo', 'out_manual' => 'manual', 'out_phone' => 'celular', default => null,
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
                            <div class="mt-0.5 flex items-center justify-end gap-1 text-[10px] opacity-70">
                                @if ($label)<span class="uppercase">{{ $label }}</span>@endif
                                <span>{{ $msg['at']?->paraExibicao()->format('d/m H:i') }}</span>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="text-center text-sm text-zinc-400">Sem mensagens nesta conversa.</div>
                @endforelse
            </div>

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
                        <button type="submit" wire:loading.attr="disabled" wire:target="sendManual"
                            class="inline-flex items-center gap-1.5 rounded-full bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-60">
                            <flux:icon icon="paper-airplane" variant="micro" wire:loading.remove wire:target="sendManual" />
                            <flux:icon icon="arrow-path" variant="micro" class="animate-spin" wire:loading wire:target="sendManual" />
                            Enviar
                        </button>
                    </form>
                    <p class="mt-1 text-[11px] text-zinc-400">Envio manual envia de verdade (respeita tetos, ignora o kill switch).</p>
                @endif
            </div>
        @endif
    </section>

    {{-- MODAL: confirmar silenciar --}}
    @if ($confirmingMuteJid)
        <x-modal wireClose="cancelMute" title="Silenciar contato">
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                Silenciar <strong>{{ $mutingName }}</strong>? O robo nunca vai auto-responder este contato (off).
                Voce ainda pode mandar mensagem manual.
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
