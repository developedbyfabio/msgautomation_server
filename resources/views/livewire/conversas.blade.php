<div class="flex h-full" wire:poll.5s>
    {{-- LISTA DE CONVERSAS --}}
    <aside class="flex w-80 shrink-0 flex-col border-r border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <div class="shrink-0 border-b border-zinc-100 p-2.5 dark:border-zinc-800">
            <div class="relative">
                <span class="pointer-events-none absolute inset-y-0 left-2.5 flex items-center text-zinc-400">
                    <flux:icon icon="magnifying-glass" variant="micro" />
                </span>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Buscar conversa..."
                    class="w-full rounded-lg border border-zinc-200 bg-zinc-50 py-2 pl-8 pr-3 text-sm focus:border-emerald-400 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800">
            </div>
        </div>
        <div class="min-h-0 flex-1 overflow-y-auto">
        @forelse ($conversations as $conv)
            <div wire:key="conv-{{ $conv['jid'] }}"
                @class([
                    'group flex items-center border-b border-zinc-100 dark:border-zinc-800',
                    'bg-zinc-100 dark:bg-zinc-800' => $selectedJid === $conv['jid'],
                    'hover:bg-zinc-50 dark:hover:bg-zinc-800/50' => $selectedJid !== $conv['jid'],
                ])>
                <button type="button" wire:click="select('{{ $conv['jid'] }}')" class="flex min-w-0 flex-1 items-center gap-3 px-3 py-3 text-left">
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-full text-sm font-medium {{ $conv['is_group'] ? 'bg-zinc-200 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-200' : 'text-white' }}"
                        @unless($conv['is_group']) style="background-color: {{ '#' . substr(md5($conv['jid']), 0, 6) }}" @endunless>
                        @if ($conv['is_group'])
                            <flux:icon icon="user-group" variant="micro" />
                        @else
                            {{ mb_strtoupper(mb_substr($conv['name'], 0, 1)) }}
                        @endif
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
                        <div class="truncate text-sm text-zinc-500"><x-msg-preview :preview="$conv['preview']" /></div>
                    </div>
                    <div class="shrink-0 self-start pt-0.5 text-[11px] text-zinc-400">{{ $conv['time_label'] }}</div>
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
                <p class="text-sm">{{ $search !== '' ? 'Nenhuma conversa encontrada.' : 'Nenhuma conversa ainda.' }}</p>
            </div>
        @endforelse
        </div>
    </aside>

    {{-- THREAD --}}
    <section class="flex min-w-0 flex-1 flex-col bg-zinc-50 dark:bg-zinc-950">
        @if (! $selectedJid)
            <div class="flex h-full flex-col items-center justify-center gap-2 text-zinc-400">
                <flux:icon icon="chat-bubble-left-right" class="size-10" />
                <p class="text-sm">Selecione uma conversa.</p>
            </div>
        @else
            @php $modoAtual = $selectedContact?->auto_reply_mode ?? 'default'; @endphp
            <div class="flex shrink-0 items-center gap-3 border-b border-zinc-200 bg-white px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900">
                {{-- Nome/numero clicavel -> abre painel de info (S4). --}}
                <button type="button" wire:click="openContactPanel" @disabled($isGroup)
                    class="flex min-w-0 flex-1 items-center gap-3 rounded-lg p-1 text-left transition enabled:hover:bg-zinc-100 disabled:cursor-default dark:enabled:hover:bg-zinc-800">
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-full {{ $isGroup ? 'bg-zinc-200 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-200' : 'text-white' }}"
                        @unless($isGroup) style="background-color: {{ '#' . substr(md5($selectedJid), 0, 6) }}" @endunless>
                        {{ mb_strtoupper(mb_substr($selectedContact?->push_name ?: \Illuminate\Support\Str::before($selectedJid, '@'), 0, 1)) }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-1.5">
                            <span class="truncate font-medium">{{ $selectedContact?->push_name ?: \Illuminate\Support\Str::before($selectedJid, '@') }}</span>
                            @if ($selectedContact?->saved)
                                <flux:icon icon="bookmark" variant="micro" class="text-emerald-500" title="Salvo nos contatos" />
                            @endif
                        </div>
                        <div class="truncate text-xs text-zinc-500">{{ \Illuminate\Support\Str::before($selectedJid, '@') }}@unless($isGroup) · toque para ver/editar @endunless</div>
                    </div>
                </button>

                @unless ($isGroup)
                    <div class="flex items-center gap-2 text-xs">
                        @php
                            [$badgeCls, $badgeTxt] = match ($modoAtual) {
                                'on' => ['bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300', 'responde (on)'],
                                'off' => ['bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300', 'silenciado (off)'],
                                default => ['bg-zinc-200 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300', 'segue a politica (default)'],
                            };
                        @endphp
                        <flux:tooltip content="Estado atual da auto-resposta deste contato. on = robo responde (sob allowlist); off = nunca; default = segue a politica de Configuracoes.">
                            <span class="rounded-full px-2 py-0.5 font-medium {{ $badgeCls }}">auto: {{ $badgeTxt }}</span>
                        </flux:tooltip>

                        <flux:tooltip content="Aprovar = o robo passa a responder ESTE contato automaticamente (auto_reply_mode = on).">
                            <button type="button" wire:click="approveJid('{{ $selectedJid }}')" @class([
                                'inline-flex items-center gap-1 rounded-lg border px-2 py-1',
                                'border-emerald-400 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' => $modoAtual === 'on',
                                'border-emerald-300 text-emerald-700 hover:bg-emerald-50 dark:border-emerald-800 dark:text-emerald-300 dark:hover:bg-emerald-950' => $modoAtual !== 'on',
                            ])>
                                <flux:icon icon="check-circle" variant="micro" /> Aprovar
                            </button>
                        </flux:tooltip>

                        <flux:tooltip content="Silenciar = o robo NUNCA responde este contato (auto_reply_mode = off). Voce ainda pode mandar mensagem manual.">
                            <button type="button" wire:click="confirmMute('{{ $selectedJid }}')" @class([
                                'inline-flex items-center gap-1 rounded-lg border px-2 py-1',
                                'border-red-400 bg-red-50 text-red-700 dark:border-red-700 dark:bg-red-950 dark:text-red-300' => $modoAtual === 'off',
                                'border-zinc-300 hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800' => $modoAtual !== 'off',
                            ])>
                                <flux:icon icon="no-symbol" variant="micro" /> Silenciar
                            </button>
                        </flux:tooltip>
                    </div>
                @endunless
            </div>

            <div class="relative flex min-h-0 flex-1 flex-col" wire:key="thread-{{ $selectedJid }}"
                x-data="{
                    atBottom: true,
                    obs: null,
                    scrollToBottom(behavior = 'smooth') {
                        const el = this.$refs.scroller; if (!el) return;
                        el.scrollTo({ top: el.scrollHeight, behavior });
                        this.atBottom = true;
                    },
                    onScroll() {
                        const el = this.$refs.scroller; if (!el) return;
                        this.atBottom = (el.scrollHeight - el.scrollTop - el.clientHeight) < 60;
                    },
                    init() {
                        this.$nextTick(() => this.scrollToBottom('auto'));
                        this.obs = new MutationObserver(() => { if (this.atBottom) this.scrollToBottom('auto'); });
                        this.obs.observe(this.$refs.scroller, { childList: true, subtree: true });
                    },
                    destroy() { this.obs?.disconnect(); }
                }">
                <div x-ref="scroller" @scroll="onScroll" class="flex-1 overflow-y-auto p-4">
                @forelse ($thread as $msg)
                    @php
                        $isIn = $msg['side'] === 'in';
                        // Origem (sutil): de quem partiu a mensagem enviada.
                        [$origLabel, $origIcon] = match ($msg['kind']) {
                            'out_bot' => ['robo', 'bolt'],
                            'out_manual' => ['manual', 'hand-raised'],
                            'out_phone' => ['celular', 'device-phone-mobile'],
                            default => [null, null],
                        };
                    @endphp

                    @if ($msg['separator'])
                        <div class="my-3 flex justify-center">
                            <span class="rounded-full bg-zinc-200/80 px-3 py-1 text-[11px] font-medium text-zinc-600 shadow-sm dark:bg-zinc-800 dark:text-zinc-300">{{ $msg['separator'] }}</span>
                        </div>
                    @endif

                    <div @class(['flex', 'justify-start' => $isIn, 'justify-end' => ! $isIn, 'mt-0.5' => $msg['grouped'], 'mt-2' => ! $msg['grouped']])>
                        <div @class([
                            'relative max-w-[75%] px-3 py-2 text-sm shadow-sm',
                            // Bolha recebida: clara, a esquerda. Enviada: verde, a direita.
                            'bg-white text-zinc-800 dark:bg-zinc-800 dark:text-zinc-100' => $isIn,
                            'bg-emerald-100 text-zinc-800 dark:bg-emerald-900 dark:text-emerald-50' => ! $isIn,
                            // Cantos estilo WhatsApp; "bico" some quando agrupado.
                            'rounded-2xl' => $msg['grouped'],
                            'rounded-2xl rounded-tl-sm' => ! $msg['grouped'] && $isIn,
                            'rounded-2xl rounded-tr-sm' => ! $msg['grouped'] && ! $isIn,
                        ])>
                            <div class="whitespace-pre-wrap break-words"><x-msg-preview :preview="$msg['preview']" /></div>
                            <div class="mt-0.5 flex items-center justify-end gap-1 text-[10px] text-zinc-500 dark:text-zinc-400">
                                @if ($origLabel)
                                    <flux:tooltip content="Origem: {{ $origLabel }}">
                                        <span class="inline-flex items-center gap-0.5">
                                            <flux:icon :icon="$origIcon" variant="micro" class="size-3 opacity-60" />
                                        </span>
                                    </flux:tooltip>
                                @endif
                                <span>{{ $msg['at']?->paraExibicao()->format('H:i') }}</span>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="text-center text-sm text-zinc-400">Sem mensagens nesta conversa.</div>
                @endforelse
                </div>

                {{-- S1: ir para a ultima mensagem (aparece so quando rolado pra cima) --}}
                <button type="button" x-show="!atBottom" x-transition @click="scrollToBottom()"
                    class="absolute bottom-3 right-4 inline-flex size-9 items-center justify-center rounded-full border border-zinc-200 bg-white text-zinc-600 shadow-lg hover:bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                    aria-label="Ir para a ultima mensagem">
                    <flux:icon icon="chevron-down" variant="micro" />
                </button>
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

    {{-- PAINEL DE INFO DO CONTATO (S4) — drawer lateral --}}
    @if ($showContactPanel && $selectedJid && ! $isGroup)
        @php $numero = \Illuminate\Support\Str::before($selectedJid, '@'); @endphp
        <div class="fixed inset-0 z-40" x-data>
            <div class="absolute inset-0 bg-black/40" wire:click="closeContactPanel"></div>
            <aside class="absolute right-0 top-0 flex h-full w-full max-w-sm flex-col overflow-y-auto border-l border-zinc-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                    <h2 class="font-semibold">Dados do contato</h2>
                    <button type="button" wire:click="closeContactPanel" class="rounded-lg p-1.5 text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800" aria-label="Fechar">
                        <flux:icon icon="x-mark" variant="micro" />
                    </button>
                </div>

                <div class="flex flex-col items-center gap-2 px-4 py-5">
                    <div class="flex size-20 items-center justify-center rounded-full text-2xl font-semibold text-white" style="background-color: {{ '#' . substr(md5($selectedJid), 0, 6) }}">
                        {{ mb_strtoupper(mb_substr($selectedContact?->push_name ?: $numero, 0, 1)) }}
                    </div>
                    <div class="text-center">
                        <div class="flex items-center justify-center gap-1.5 font-medium">
                            {{ $selectedContact?->push_name ?: 'Sem nome' }}
                            @if ($selectedContact?->saved)
                                <flux:icon icon="bookmark" variant="micro" class="text-emerald-500" title="Salvo" />
                            @endif
                        </div>
                        <div class="text-sm text-zinc-500">{{ $numero }}</div>
                    </div>
                </div>

                {{-- Auto-resposta (mesmo vocabulario do S5) --}}
                <div class="border-t border-zinc-200 px-4 py-4 dark:border-zinc-800">
                    <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-400">Auto-resposta</div>
                    <p class="mb-2 text-xs text-zinc-500">
                        <strong>on</strong> = robo responde (sob allowlist) · <strong>off</strong> = nunca responde ·
                        <strong>default</strong> = segue a politica de Configuracoes.
                    </p>
                    <p class="mb-3 rounded-md bg-zinc-50 px-2 py-1.5 text-[11px] text-zinc-500 dark:bg-zinc-800/50">
                        <flux:icon icon="information-circle" variant="micro" class="inline size-3" />
                        O robo so responde mensagens <strong>recebidas</strong> deste contato — nunca as que <strong>voce</strong> envia (mensagens proprias sao ignoradas).
                    </p>
                    <div class="grid grid-cols-3 gap-2">
                        <button type="button" wire:click="setSelectedMode('on')" @class([
                            'rounded-lg border px-2 py-2 text-sm font-medium',
                            'border-emerald-500 bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' => $modoAtual === 'on',
                            'border-zinc-300 text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800' => $modoAtual !== 'on',
                        ])>on</button>
                        <button type="button" wire:click="setSelectedMode('default')" @class([
                            'rounded-lg border px-2 py-2 text-sm font-medium',
                            'border-zinc-500 bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200' => $modoAtual === 'default',
                            'border-zinc-300 text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800' => $modoAtual !== 'default',
                        ])>default</button>
                        <button type="button" wire:click="confirmMute('{{ $selectedJid }}')" @class([
                            'rounded-lg border px-2 py-2 text-sm font-medium',
                            'border-red-500 bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300' => $modoAtual === 'off',
                            'border-zinc-300 text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800' => $modoAtual !== 'off',
                        ])>off</button>
                    </div>
                </div>

                {{-- Nome/notas --}}
                <div class="border-t border-zinc-200 px-4 py-4 dark:border-zinc-800">
                    <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-400">Adicionar aos contatos</div>
                    <div class="space-y-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium">Nome</label>
                            <input type="text" wire:model="panelName" placeholder="Dar um nome..."
                                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium">Notas</label>
                            <textarea wire:model="panelNotes" rows="3" placeholder="Anotacoes internas..."
                                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"></textarea>
                        </div>
                        <button type="button" wire:click="saveContact"
                            class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                            <flux:icon icon="check" variant="micro" /> Salvar contato
                        </button>
                    </div>
                </div>

                {{-- Midias recentes (so lista; render real e fatia futura) --}}
                <div class="border-t border-zinc-200 px-4 py-4 dark:border-zinc-800">
                    <div class="mb-2 flex items-center justify-between">
                        <span class="text-xs font-semibold uppercase tracking-wide text-zinc-400">Midias recentes</span>
                        <span class="text-[10px] text-zinc-400">render na fatia futura</span>
                    </div>
                    @forelse ($recentMedia as $m)
                        <div class="flex items-center gap-2 py-1.5 text-sm" wire:key="media-{{ $loop->index }}">
                            <flux:icon :icon="$m['icon']" variant="micro" class="text-zinc-400" />
                            <span class="flex-1">{{ $m['label'] }}</span>
                            <span class="text-xs text-zinc-400">{{ $m['at']?->paraExibicao()->format('d/m H:i') }}</span>
                        </div>
                    @empty
                        <p class="text-xs text-zinc-400">Nenhuma midia trocada nesta conversa.</p>
                    @endforelse
                </div>
            </aside>
        </div>
    @endif

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
