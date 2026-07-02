@php
    // Barra horizontal proporcional (CSS puro — sem lib de grafico).
    $barra = function (int $valor, int $max) {
        $pct = $max > 0 ? max(2, (int) round($valor / $max * 100)) : 0;

        return $valor > 0 ? $pct : 0;
    };
    $fmtMediana = function (?int $s) {
        if ($s === null) return '—';
        if ($s < 60) return $s . 's';
        if ($s < 3600) return intdiv($s, 60) . 'min ' . ($s % 60) . 's';

        return intdiv($s, 3600) . 'h ' . intdiv($s % 3600, 60) . 'min';
    };
@endphp
<div class="h-full overflow-y-auto">
    <div class="mx-auto max-w-4xl space-y-4 p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-1">
                <h1 class="text-xl font-semibold">Painel</h1>
                <x-info-tip text="Numeros que o sistema ja registra (logs de envio, decisoes da IA, sessoes de fluxo, Kanban, proativas), agregados por periodo no fuso de Sao Paulo. Leitura pura com cache de 60s — use Atualizar pra forcar." />
            </div>
            <div class="flex items-center gap-2">
                @foreach ($periodos as $valor => $rotulo)
                    <button type="button" wire:click="setPeriodo('{{ $valor }}')"
                        @class([
                            'rounded-full px-3 py-1.5 text-sm font-medium transition',
                            'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $periodo === $valor,
                            'border border-zinc-300 hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800' => $periodo !== $valor,
                        ])>{{ $rotulo }}</button>
                @endforeach
                <button type="button" wire:click="atualizar"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 px-3 py-1.5 text-sm font-medium hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">
                    <flux:icon icon="arrow-path" variant="micro" /> Atualizar
                </button>
            </div>
        </div>

        {{-- CARDS DE TOPO --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="text-3xl font-semibold">{{ $dados['resumo']['recebidas'] }}</div>
                <div class="mt-1 text-xs text-zinc-500">mensagens recebidas
                    @if ($dados['resumo']['grupos'] > 0)<span class="text-zinc-400">(+{{ $dados['resumo']['grupos'] }} em grupos)</span>@endif
                </div>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="text-3xl font-semibold">{{ $dados['resumo']['enviadas'] }}</div>
                <div class="mt-1 text-xs text-zinc-500">respostas enviadas</div>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="text-3xl font-semibold">{{ $dados['resumo']['pct_automatico'] }}%</div>
                <div class="mt-1 flex items-center gap-1 text-xs text-zinc-500">automatico
                    <x-info-tip text="Percentual das respostas enviadas que sairam do robo sozinho (modo auto: regra, fluxo ou IA), sobre o total enviado no periodo." />
                </div>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="text-3xl font-semibold">{{ $fmtMediana($dados['resumo']['mediana_primeira_resposta']) }}</div>
                <div class="mt-1 flex items-center gap-1 text-xs text-zinc-500">mediana 1a resposta
                    <x-info-tip text="Por contato no periodo: da primeira mensagem dele ate a primeira resposta (mediana — imune a outliers de horas). Contato sem resposta nao entra." />
                </div>
            </div>
        </div>

        {{-- RESPOSTAS POR ORIGEM --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-2 text-sm font-semibold">Respostas por origem</div>
            @php $maxOrigem = max(1, max($dados['origens'] ?: [0])); @endphp
            <div class="space-y-1.5">
                @foreach ($dados['origens'] as $rotulo => $total)
                    <div class="flex items-center gap-2 text-xs" wire:key="orig-{{ $loop->index }}">
                        <span class="w-36 shrink-0 text-zinc-500">{{ $rotulo }}</span>
                        <div class="h-3 flex-1 overflow-hidden rounded bg-zinc-100 dark:bg-zinc-800">
                            <div class="h-full rounded bg-sky-500" style="width: {{ $barra($total, $maxOrigem) }}%"></div>
                        </div>
                        <span class="w-8 shrink-0 text-right font-medium">{{ $total }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            {{-- IA --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="mb-2 flex items-center gap-1 text-sm font-semibold"><flux:icon icon="sparkles" variant="micro" class="text-indigo-500" /> IA</div>
                <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs">
                    <span>respondeu: <strong>{{ $dados['ia']['por_acao']['respondeu'] ?? 0 }}</strong></span>
                    <span>escalou: <strong>{{ $dados['ia']['por_acao']['escalou'] ?? 0 }}</strong></span>
                    <span>silenciou: <strong>{{ $dados['ia']['por_acao']['silenciou'] ?? 0 }}</strong></span>
                </div>
                <div class="mt-2 text-xs text-zinc-500">
                    Consumo hoje: <strong>{{ $dados['ia']['consumo_dia'] }}</strong> / {{ $dados['ia']['cota_dia'] }} chamadas ·
                    Pendencias: <a href="{{ route('revisao') }}" wire:navigate class="font-medium underline">{{ $dados['ia']['pendencias'] }}</a>
                </div>
                @if ($dados['ia']['top_intents'] !== [])
                    <div class="mt-2 text-[11px] uppercase tracking-wide text-zinc-400">Intents mais frequentes</div>
                    @foreach ($dados['ia']['top_intents'] as $intent => $total)
                        <div class="flex justify-between text-xs" wire:key="int-{{ $loop->index }}"><span>{{ $intent }}</span><span class="font-medium">{{ $total }}</span></div>
                    @endforeach
                @else
                    <p class="mt-2 text-xs text-zinc-400">Nenhuma decisao com intent no periodo.</p>
                @endif
            </div>

            {{-- FLUXOS --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="mb-2 flex items-center gap-1 text-sm font-semibold"><flux:icon icon="rectangle-stack" variant="micro" class="text-sky-500" /> Fluxos</div>
                <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs">
                    <span>iniciadas: <strong>{{ $dados['fluxos']['iniciadas'] }}</strong></span>
                    <span>concluidas: <strong>{{ $dados['fluxos']['concluidas'] }}</strong></span>
                    <span>expiradas: <strong>{{ $dados['fluxos']['expiradas'] }}</strong></span>
                </div>
                @if ($dados['fluxos']['top'] !== [])
                    <div class="mt-2 text-[11px] uppercase tracking-wide text-zinc-400">Mais usados</div>
                    @foreach ($dados['fluxos']['top'] as $nome => $total)
                        <div class="flex justify-between text-xs" wire:key="fl-{{ $loop->index }}"><span>{{ $nome }}</span><span class="font-medium">{{ $total }}</span></div>
                    @endforeach
                @else
                    <p class="mt-2 text-xs text-zinc-400">Nenhuma sessao de fluxo no periodo.</p>
                @endif
            </div>

            {{-- KANBAN --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="mb-2 flex items-center gap-1 text-sm font-semibold"><flux:icon icon="view-columns" variant="micro" class="text-emerald-500" /> Kanban</div>
                <div class="text-xs">cards criados no periodo: <strong>{{ $dados['kanban']['criados'] }}</strong></div>
                @if ($dados['kanban']['transicoes'] !== [])
                    <div class="mt-2 text-[11px] uppercase tracking-wide text-zinc-400">Movimentos por coluna destino</div>
                    @php $maxTrans = max(1, max($dados['kanban']['transicoes'])); @endphp
                    @foreach ($dados['kanban']['transicoes'] as $col => $total)
                        <div class="flex items-center gap-2 text-xs" wire:key="kt-{{ $loop->index }}">
                            <span class="w-28 shrink-0 truncate text-zinc-500">{{ $col }}</span>
                            <div class="h-3 flex-1 overflow-hidden rounded bg-zinc-100 dark:bg-zinc-800">
                                <div class="h-full rounded bg-emerald-500" style="width: {{ $barra($total, $maxTrans) }}%"></div>
                            </div>
                            <span class="w-8 shrink-0 text-right font-medium">{{ $total }}</span>
                        </div>
                    @endforeach
                @endif
                @if ($dados['kanban']['agora'] !== [])
                    <div class="mt-2 text-[11px] uppercase tracking-wide text-zinc-400">Agora (retrato)</div>
                    <div class="flex flex-wrap gap-x-3 gap-y-1 text-xs">
                        @foreach ($dados['kanban']['agora'] as $col => $total)
                            <span wire:key="ka-{{ $loop->index }}">{{ $col }}: <strong>{{ $total }}</strong></span>
                        @endforeach
                    </div>
                @else
                    <p class="mt-2 text-xs text-zinc-400">Nenhum card ainda.</p>
                @endif
            </div>

            {{-- PROATIVAS --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="mb-2 flex items-center gap-1 text-sm font-semibold"><flux:icon icon="megaphone" variant="micro" class="text-rose-500" /> Proativas</div>
                @php $temAtividade = $dados['proativas']['enviadas'] > 0 || $dados['proativas']['puladas'] !== [] || $dados['proativas']['falhadas'] > 0 || $dados['proativas']['campanhas_ativas'] > 0; @endphp
                @if ($temAtividade)
                    <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs">
                        <span>enviadas: <strong>{{ $dados['proativas']['enviadas'] }}</strong></span>
                        <span>falhadas: <strong>{{ $dados['proativas']['falhadas'] }}</strong></span>
                        <span>campanhas ativas: <strong>{{ $dados['proativas']['campanhas_ativas'] }}</strong></span>
                    </div>
                    @if ($dados['proativas']['puladas'] !== [])
                        <div class="mt-2 text-[11px] uppercase tracking-wide text-zinc-400">Puladas por motivo</div>
                        @php $maxPul = max(1, max($dados['proativas']['puladas'])); @endphp
                        @foreach ($dados['proativas']['puladas'] as $motivo => $total)
                            <div class="flex items-center gap-2 text-xs" wire:key="pp-{{ $loop->index }}">
                                <span class="w-28 shrink-0 truncate text-zinc-500">{{ $motivo ?: '-' }}</span>
                                <div class="h-3 flex-1 overflow-hidden rounded bg-zinc-100 dark:bg-zinc-800">
                                    <div class="h-full rounded bg-amber-500" style="width: {{ $barra($total, $maxPul) }}%"></div>
                                </div>
                                <span class="w-8 shrink-0 text-right font-medium">{{ $total }}</span>
                            </div>
                        @endforeach
                    @endif
                    <div class="mt-2 text-xs text-zinc-500">Teto de hoje: <strong>{{ $dados['proativas']['consumo_dia'] }}</strong> / {{ $dados['proativas']['teto_dia'] }}</div>
                @else
                    <p class="text-xs text-zinc-400">Nenhuma atividade proativa (interruptor desligado — e isso e bom ate voce precisar).</p>
                @endif
            </div>
        </div>

        {{-- MATCH-1: sem-match -> oportunidade de regra --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-2 flex items-center gap-1 text-sm font-semibold">
                Sem resposta ({{ $periodos[$periodo] }})
                <x-info-tip text="Mensagens de contatos APROVADOS que terminaram em silencio: nenhuma regra/fluxo casou e a IA nao respondeu. Grupos e contatos silenciados nao entram. Retencao de 30 dias. Cada item pode VIRAR REGRA pelo mesmo caminho oficial (todas as guardas)." />
                <span class="ml-auto rounded bg-zinc-100 px-2 py-0.5 text-xs font-medium dark:bg-zinc-800">{{ $semResposta['total'] }}</span>
            </div>
            @forelse ($semResposta['itens'] as $u)
                <div class="flex items-center gap-2 border-t border-zinc-100 py-1.5 text-sm first:border-t-0 dark:border-zinc-800" wire:key="um-{{ $u->id }}">
                    <span class="min-w-0 flex-1 truncate" title="{{ $u->text }}">"{{ $u->text }}"</span>
                    <span class="shrink-0 text-[11px] text-zinc-400">{{ $u->vezes }}x</span>
                    <button type="button" wire:click="abrirVirarRegra({{ $u->id }})"
                        class="shrink-0 rounded-md border border-zinc-300 px-2 py-1 text-xs font-medium hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">
                        Virar regra
                    </button>
                </div>
            @empty
                <p class="text-xs text-zinc-400">Nenhum silencio elegivel no periodo — tudo que chegou de contato aprovado foi respondido (ou virou pendencia).</p>
            @endforelse
        </div>

    {{-- MODAL: virar regra (caminho oficial, gatilho tolerante por default) --}}
    @if ($promoteUnmatchedId)
        <x-modal wireClose="fecharVirarRegra" title="Virar regra (a partir do sem-match)">
            <div class="space-y-3">
                <div>
                    <label class="mb-1 block text-xs font-medium">Gatilho (Contem, tolerante a erros de digitacao)</label>
                    <input type="text" wire:model="uTrigger" data-autofocus class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    @error('uTrigger') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Resposta</label>
                    <textarea wire:model="uResponse" rows="3" placeholder="ex.: {saudacao}, {nome}! ..." class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"></textarea>
                    @error('uResponse') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    <p class="mt-1 text-[11px] text-zinc-400">Regra nasce GLOBAL e ativa. Caixa, acentos e pontuacao sao ignorados no casamento. Ajustes finos (escopo, mais gatilhos) em /regras.</p>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="fecharVirarRegra" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                    <button type="button" wire:click="confirmVirarRegra" class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-zinc-900">Criar regra</button>
                </div>
            </div>
        </x-modal>
    @endif

    </div>
</div>
