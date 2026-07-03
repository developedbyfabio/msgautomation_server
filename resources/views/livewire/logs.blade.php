<div class="mx-auto max-w-5xl space-y-4 p-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold">Logs</h1>
            <p class="text-sm text-zinc-500">Eventos da conta em horário de São Paulo. Somente leitura.</p>
        </div>
        <div class="flex flex-wrap gap-2 text-sm">
            <select wire:model.live="tipo" aria-label="Tipo de evento"
                class="rounded-lg border border-zinc-300 bg-white px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                @foreach (\App\Livewire\Logs::TIPOS as $valor => $rotulo)
                    <option value="{{ $valor }}">{{ $rotulo }}</option>
                @endforeach
            </select>
            <select wire:model.live="canal" aria-label="Canal"
                class="rounded-lg border border-zinc-300 bg-white px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                <option value="todos">Todos os canais</option>
                <option value="evolution">Evolution</option>
                <option value="cloud_api">Cloud (Meta)</option>
            </select>
            <select wire:model.live="periodo" aria-label="Período"
                class="rounded-lg border border-zinc-300 bg-white px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                <option value="hoje">Hoje</option>
                <option value="24h">Últimas 24h</option>
                <option value="7d">Últimos 7 dias</option>
            </select>
        </div>
    </div>

    <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        @forelse ($eventos as $e)
            <div wire:key="ev-{{ $loop->index }}-{{ $e['quando']?->timestamp }}" x-data="{ aberto: false }"
                class="border-b border-zinc-100 px-4 py-2.5 last:border-0 dark:border-zinc-800 {{ $e['nivel'] === 'error' ? 'bg-red-50/60 dark:bg-red-950/20' : '' }}">
                <div class="flex items-start gap-3">
                    <span @class([
                        'mt-0.5 inline-flex shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase',
                        'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300' => $e['nivel'] === 'error',
                        'bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-300' => $e['nivel'] === 'warning',
                        'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' => $e['nivel'] === 'info',
                    ])>{{ $e['nivel'] === 'error' ? 'FALHA' : ($e['nivel'] === 'warning' ? 'ATENÇÃO' : \App\Livewire\Logs::TIPOS[$e['tipo']] ?? $e['tipo']) }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm {{ $e['nivel'] === 'error' ? 'font-medium text-red-800 dark:text-red-200' : '' }}">{{ $e['titulo'] }}</p>
                        @if ($e['detalhe'])
                            <p class="truncate text-xs text-zinc-500">{{ $e['detalhe'] }}</p>
                        @endif
                        @if ($e['extra'])
                            <button type="button" @click="aberto = !aberto" class="mt-0.5 text-[11px] text-zinc-400 underline-offset-2 hover:underline">
                                <span x-show="!aberto">ver detalhe</span><span x-show="aberto" x-cloak>esconder</span>
                            </button>
                            <pre x-show="aberto" x-cloak class="mt-1 overflow-x-auto rounded-lg bg-zinc-100 p-2 text-[11px] dark:bg-zinc-800">{{ json_encode($e['extra'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                        @endif
                    </div>
                    <span class="shrink-0 text-xs tabular-nums text-zinc-400" title="Horário de São Paulo">
                        {{ $e['quando']?->paraExibicao()->format('d/m H:i:s') }}
                    </span>
                </div>
            </div>
        @empty
            <p class="p-8 text-center text-sm text-zinc-400">Nenhum evento no filtro/período escolhido.</p>
        @endforelse
    </div>

    @if ($temMais)
        <div class="text-center">
            <button wire:click="carregarMais" wire:loading.attr="disabled"
                class="rounded-lg border border-zinc-300 px-4 py-2 text-sm hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800">
                Carregar mais
            </button>
        </div>
    @endif
</div>
