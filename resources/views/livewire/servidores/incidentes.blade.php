<div class="h-full overflow-y-auto">
    <div class="mx-auto max-w-4xl p-6 space-y-4">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <h1 class="text-xl font-semibold">Incidentes</h1>
                <x-info-tip text="Incidentes abertos pela avaliacao (histerese + watchdog). Reconhecer silencia a repeticao; quem resolve e a normalizacao da metrica (ou o servidor voltar a reportar). Nesta fase o sistema esta em MODO SILENCIOSO: as transicoes aparecem aqui e nos Logs, nada e enviado por WhatsApp." />
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @foreach (['abertos' => 'Abertos', 'todos' => 'Todos', 'resolvidos' => 'Resolvidos'] as $chave => $rotulo)
                <button type="button" wire:click="setFiltro('{{ $chave }}')"
                    @class([
                        'rounded-lg px-3 py-1.5 text-sm',
                        'bg-zinc-900 font-medium text-white dark:bg-white dark:text-zinc-900' => $filtro === $chave,
                        'border border-zinc-300 text-zinc-600 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800' => $filtro !== $chave,
                    ])>{{ $rotulo }}</button>
            @endforeach
            <select wire:model.live="servidorId" aria-label="Filtrar por servidor"
                class="ml-auto rounded-lg border border-zinc-300 bg-white px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                <option value="">Todos os servidores</option>
                @foreach ($servers as $s)
                    <option value="{{ $s->id }}">{{ $s->name }}</option>
                @endforeach
            </select>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white divide-y divide-zinc-100 dark:border-zinc-800 dark:bg-zinc-900 dark:divide-zinc-800">
            @forelse ($incidents as $i)
                <div class="flex items-center gap-3 p-3" wire:key="inc-{{ $i->id }}">
                    <div @class([
                        'flex size-9 shrink-0 items-center justify-center rounded-full',
                        'bg-red-100 text-red-600 dark:bg-red-950 dark:text-red-400' => $i->level === 'critical' && $i->isOpen(),
                        'bg-amber-100 text-amber-600 dark:bg-amber-950 dark:text-amber-400' => $i->level === 'warning' && $i->isOpen(),
                        'bg-zinc-100 text-zinc-500 dark:bg-zinc-800' => ! $i->isOpen(),
                    ])>
                        <flux:icon icon="{{ $i->metric === 'watchdog' ? 'signal-slash' : 'exclamation-triangle' }}" variant="micro" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="truncate font-medium">{{ $i->server?->name ?? '#'.$i->server_id }}</span>
                            <span class="text-sm text-zinc-500">{{ \App\Servers\AlertRule::LABELS[$i->metric] ?? $i->metric }}@if ($i->mount) <code class="text-xs">{{ $i->mount }}</code>@endif</span>
                            {{-- nivel --}}
                            <span @class([
                                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' => $i->level === 'critical',
                                'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300' => $i->level === 'warning',
                            ])>{{ $i->level }}</span>
                            {{-- estado --}}
                            <span @class([
                                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' => $i->status === 'firing',
                                'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300' => $i->status === 'acknowledged',
                                'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' => $i->status === 'resolved',
                            ])>{{ ['firing' => 'disparado', 'acknowledged' => 'reconhecido', 'resolved' => 'resolvido'][$i->status] }}</span>
                        </div>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-zinc-400">
                            <span>inicio {{ $i->started_at->paraExibicao()->format('d/m H:i:s') }}</span>
                            @if ($i->value_at_fire !== null)
                                <span aria-hidden="true">&middot;</span>
                                <span>valor {{ $i->metric === 'watchdog' ? (int) $i->value_at_fire.'s sem reportar' : $i->value_at_fire }}</span>
                            @endif
                            @if ($i->resolved_at)
                                <span aria-hidden="true">&middot;</span>
                                <span>resolvido {{ $i->resolved_at->paraExibicao()->format('d/m H:i:s') }}</span>
                            @elseif ($i->acknowledged_at)
                                <span aria-hidden="true">&middot;</span>
                                <span>reconhecido {{ $i->acknowledged_at->paraExibicao()->format('d/m H:i') }}</span>
                            @endif
                        </div>
                    </div>
                    @if ($i->status === 'firing')
                        <button type="button" wire:click="ack({{ $i->id }})"
                            class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-zinc-300 px-2.5 py-1.5 text-xs font-medium text-zinc-600 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                            <flux:icon icon="check" variant="micro" /> Reconhecer
                        </button>
                    @endif
                </div>
            @empty
                <div class="flex flex-col items-center gap-2 p-10 text-center text-zinc-400">
                    <flux:icon icon="shield-check" class="size-8" />
                    <p class="text-sm">{{ $filtro === 'abertos' ? 'Nenhum incidente aberto. Tudo normal.' : 'Nenhum incidente no filtro.' }}</p>
                </div>
            @endforelse
        </div>
    </div>
</div>
