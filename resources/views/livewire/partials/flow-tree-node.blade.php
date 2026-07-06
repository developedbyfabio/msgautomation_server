{{-- Fatia 17 — partial RECURSIVO da arvore do fluxo (read-only). Recebe $ramo:
     ['type' => 'node'|'ref', 'node' => FlowNode, 'opcoes' => [...]] montado no
     componente com a politica EXPAND-ONCE (reencontro = referencia ↩, nunca
     re-expande — lacos e DAG terminam sempre). Nenhuma acao de escrita aqui. --}}
@php $node = $ramo['node']; @endphp

@if ($ramo['type'] === 'ref')
    {{-- Reencontro (laco "voltar" ou subarvore compartilhada): referencia, sem expandir. --}}
    <div class="flex items-center gap-1.5 text-xs text-zinc-400">
        <span aria-hidden="true">&#8617;</span> volta ao
        <span class="size-2.5 shrink-0 rounded-full {{ $node->identityColor() }}"></span>
        <span class="font-mono">no #{{ $node->display_number }}</span>
        <span class="rounded bg-zinc-100 px-1 text-[10px] dark:bg-zinc-800">{{ $node->kind }}</span>
    </div>
@else
    <div>
        <div class="flex items-center gap-2">
            <span class="size-2.5 shrink-0 rounded-full {{ $node->identityColor() }}"></span>
            <span class="font-mono text-xs font-medium">no #{{ $node->display_number }}</span>
            <span @class([
                'rounded px-1.5 py-0.5 text-[10px] font-medium',
                'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300' => $node->kind === 'handoff',
                'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300' => $node->kind === 'final',
                'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' => ! in_array($node->kind, ['final', 'handoff'], true),
            ])>{{ $node->kind }}</span>
            <span class="min-w-0 truncate text-xs text-zinc-500">{{ \Illuminate\Support\Str::limit(strip_tags((string) $node->message), 80) }}</span>
        </div>

        @if (! empty($ramo['opcoes']))
            <div class="ml-[5px] mt-1 space-y-2 border-l-2 border-zinc-200 pl-4 dark:border-zinc-700">
                @foreach ($ramo['opcoes'] as $item)
                    <div>
                        <div class="flex items-center gap-1.5 text-xs">
                            <span class="rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-[10px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">{{ $item['option']->input }}</span>
                            <span class="text-zinc-600 dark:text-zinc-300">{{ $item['option']->label ?: '(sem rotulo)' }}</span>
                            <span class="text-zinc-400" aria-hidden="true">&rarr;</span>
                            @if ($item['target'] === null)
                                <span class="text-amber-600 dark:text-amber-400">— sem destino</span>
                            @endif
                        </div>
                        @if ($item['target'] !== null)
                            <div class="mt-1 pl-1">
                                @include('livewire.partials.flow-tree-node', ['ramo' => $item['target']])
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endif
