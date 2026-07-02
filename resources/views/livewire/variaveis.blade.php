<div class="h-full overflow-y-auto">
    <div class="mx-auto max-w-4xl space-y-4 p-6">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-1">
                <h1 class="text-xl font-semibold">Variaveis (placeholders)</h1>
                <x-info-tip text="Placeholders configuraveis, resolvidos SO no envio, em todo lugar onde placeholder ja funciona (regras, fluxos, campanhas, base da IA, edicao de pendencia). Variavel e pra conteudo NAO-sensivel — senha/PIX e assunto do cofre (/senhas)." />
            </div>
            <button type="button" wire:click="novo"
                class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-3 py-2 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
                <flux:icon icon="plus" variant="micro" /> Nova variavel
            </button>
        </div>

        {{-- NATIVAS --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-2 text-sm font-semibold">Nativas</div>
            <div class="space-y-1.5 text-sm">
                <div class="flex items-center justify-between gap-2">
                    <span><code class="rounded bg-zinc-100 px-1.5 dark:bg-zinc-800">{nome}</code> <span class="text-zinc-500">— {{ $nativasPreview['nome'] }}</span></span>
                    <span class="text-[10px] text-zinc-400">nativa</span>
                </div>
                <div class="flex items-center justify-between gap-2">
                    <span><code class="rounded bg-zinc-100 px-1.5 dark:bg-zinc-800">{data}</code> <span class="text-zinc-500">— data de hoje (agora: {{ $nativasPreview['data'] }})</span></span>
                    <span class="text-[10px] text-zinc-400">nativa</span>
                </div>
                <div class="flex items-center justify-between gap-2">
                    <span><code class="rounded bg-zinc-100 px-1.5 dark:bg-zinc-800">{hora}</code> <span class="text-zinc-500">— hora do envio (agora: {{ $nativasPreview['hora'] }})</span></span>
                    <span class="text-[10px] text-zinc-400">nativa</span>
                </div>
                <div class="flex items-center justify-between gap-2">
                    <span><code class="rounded bg-zinc-100 px-1.5 dark:bg-zinc-800">{senha:nome}</code> <span class="text-zinc-500">— senha do cofre, resolvida so no POST e com escopo por contato</span></span>
                    <a href="{{ route('senhas') }}" wire:navigate class="text-[11px] font-medium underline">gerenciar no cofre</a>
                </div>
            </div>
        </div>

        {{-- LISTA (sistema + custom) --}}
        <div class="rounded-xl border border-zinc-200 bg-white divide-y divide-zinc-100 dark:border-zinc-800 dark:bg-zinc-900 dark:divide-zinc-800">
            @forelse ($variaveis as $v)
                <div class="flex items-start gap-3 p-3" wire:key="v-{{ $v->id }}">
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-teal-100 text-teal-600 dark:bg-teal-950">
                        <flux:icon icon="variable" variant="micro" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <code class="rounded bg-zinc-100 px-1.5 text-sm font-medium dark:bg-zinc-800">{{ '{' . $v->name . '}' }}</code>
                            <span class="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] text-zinc-500 dark:bg-zinc-800">{{ ['static' => 'texto fixo', 'horario' => 'por horario', 'dia_semana' => 'por dia da semana'][$v->type] ?? $v->type }}</span>
                            @if ($v->is_system)
                                <span class="rounded bg-indigo-100 px-1.5 py-0.5 text-[10px] font-medium text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300" title="Variavel de sistema: edita os textos/faixas; nao renomeia, nao exclui, nao desativa.">sistema</span>
                            @endif
                            @unless ($v->active)
                                <span class="rounded bg-zinc-200 px-1.5 py-0.5 text-[10px] text-zinc-500 dark:bg-zinc-800">inativa</span>
                            @endunless
                        </div>
                        <div class="mt-0.5 text-sm text-zinc-500">
                            Agora resolve pra: <span class="font-medium text-zinc-700 dark:text-zinc-200">"{{ $preview[$v->id] }}"</span>
                        </div>
                    </div>

                    <flux:dropdown position="bottom" align="end">
                        <button type="button" class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" aria-label="Acoes">
                            <flux:icon icon="ellipsis-vertical" variant="micro" />
                        </button>
                        <flux:menu>
                            <flux:menu.item wire:click="edit({{ $v->id }})" icon="pencil-square">Editar</flux:menu.item>
                            @unless ($v->is_system)
                                <flux:menu.item wire:click="toggle({{ $v->id }})" icon="{{ $v->active ? 'pause' : 'play' }}">{{ $v->active ? 'Desativar' : 'Ativar' }}</flux:menu.item>
                                <flux:menu.separator />
                                <flux:menu.item wire:click="confirmDelete({{ $v->id }})" icon="trash" variant="danger">Excluir</flux:menu.item>
                            @endunless
                        </flux:menu>
                    </flux:dropdown>
                </div>
            @empty
                <div class="p-6 text-center text-sm text-zinc-400">Nenhuma variavel ainda.</div>
            @endforelse
        </div>
    </div>

    {{-- MODAL: criar/editar --}}
    @if ($showForm)
        <x-modal wireClose="closeForm" title="{{ $editingId ? 'Editar variavel' : 'Nova variavel' }}" maxWidth="lg">
            <form id="var-form" wire:submit="save" class="space-y-3">
                @php $sistema = $editingId && ($variaveis->firstWhere('id', $editingId)?->is_system); @endphp
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium">Nome (use como {{ '{nome_da_variavel}' }})</label>
                        <input type="text" wire:model="vName" maxlength="40" placeholder="ex.: horario_atendimento" @disabled($sistema) data-autofocus
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-800">
                        @error('vName') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium">Tipo</label>
                        <select wire:model.live="vType" @disabled($sistema) class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-800">
                            <option value="static">Texto fixo</option>
                            <option value="horario">Por faixa de horario</option>
                            <option value="dia_semana">Por dia da semana</option>
                        </select>
                        @error('vType') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                </div>

                @if ($vType === 'static')
                    <div>
                        <label class="mb-1 block text-xs font-medium">Texto</label>
                        <textarea wire:model="cValor" rows="2" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"></textarea>
                    </div>
                @elseif ($vType === 'horario')
                    <div>
                        <div class="mb-1 flex items-center justify-between">
                            <label class="text-xs font-medium">Faixas de horario (a primeira que cobre vence; pode cruzar meia-noite)</label>
                            <button type="button" wire:click="addFaixa" class="inline-flex items-center gap-1 text-xs text-teal-600 hover:underline"><flux:icon icon="plus" variant="micro" /> faixa</button>
                        </div>
                        <div class="space-y-2">
                            @foreach ($cFaixas as $i => $f)
                                <div class="flex items-center gap-2" wire:key="fx-{{ $i }}">
                                    <input type="time" wire:model="cFaixas.{{ $i }}.inicio" class="w-28 rounded-lg border border-zinc-300 bg-white px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                                    <span class="text-xs text-zinc-400">ate</span>
                                    <input type="time" wire:model="cFaixas.{{ $i }}.fim" class="w-28 rounded-lg border border-zinc-300 bg-white px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                                    <input type="text" wire:model="cFaixas.{{ $i }}.valor" placeholder="texto nesta faixa" class="min-w-0 flex-1 rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                                    <button type="button" wire:click="removeFaixa({{ $i }})" @disabled(count($cFaixas) <= 1) class="text-zinc-400 hover:text-red-500 disabled:opacity-30"><flux:icon icon="x-mark" variant="micro" /></button>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium">Valor padrao (fora das faixas — OBRIGATORIO)</label>
                        <input type="text" wire:model="cValorPadrao" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    </div>
                @else
                    <div class="grid grid-cols-2 gap-2">
                        @foreach (\App\Livewire\Variaveis::DIAS as $slug => $rotulo)
                            <div wire:key="dia-{{ $slug }}">
                                <label class="mb-1 block text-xs font-medium">{{ $rotulo }}</label>
                                <input type="text" wire:model="cDias.{{ $slug }}" placeholder="(usa o padrao)" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                            </div>
                        @endforeach
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium">Valor padrao (dias nao preenchidos — OBRIGATORIO)</label>
                        <input type="text" wire:model="cValorPadrao" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    </div>
                @endif
                @error('cValor') <p class="text-xs text-red-500">{{ $message }}</p> @enderror

                @unless ($sistema)
                    <label class="inline-flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="vActive" class="rounded border-zinc-300 dark:border-zinc-700"> Ativa
                    </label>
                @endunless
                <p class="text-[11px] text-zinc-400">
                    Sem {{ '{senha:...}' }} (cofre) e sem outro placeholder dentro do valor (um nivel, sem recursao).
                    Resolucao em horario de Sao Paulo, so no envio.
                </p>
            </form>
            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="closeForm" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                    <button type="submit" form="var-form" class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-zinc-900">
                        <flux:icon icon="check" variant="micro" /> Salvar
                    </button>
                </div>
            </x-slot:footer>
        </x-modal>
    @endif

    {{-- MODAL: excluir (mostra USO) --}}
    @if ($deleting)
        @php $uso = $this->usoDe($deleting->name); @endphp
        <x-modal wireClose="cancelDelete" title="Excluir variavel">
            <div class="space-y-2 text-sm text-zinc-600 dark:text-zinc-300">
                <p>Excluir <code class="rounded bg-zinc-100 px-1.5 dark:bg-zinc-800">{{ '{' . $deleting->name . '}' }}</code>?</p>
                <p class="text-xs">Onde ela e usada hoje:</p>
                <ul class="list-disc pl-5 text-xs">
                    <li>{{ $uso['regras'] }} resposta(s) de regra</li>
                    <li>{{ $uso['fluxos'] }} no(s) de fluxo</li>
                    <li>{{ $uso['campanhas'] }} campanha(s)</li>
                    <li>{{ $uso['base'] }} entrada(s) da base de conhecimento</li>
                </ul>
                <p class="rounded bg-amber-50 px-2 py-1 text-xs text-amber-700 dark:bg-amber-950/50 dark:text-amber-300">
                    A referencia {{ '{' . $deleting->name . '}' }} fica INTACTA nesses textos e passa a sair
                    CRUA pro contato ate voce ajustar.
                </p>
            </div>
            <div class="flex justify-end gap-2 pt-4">
                <button type="button" wire:click="cancelDelete" data-autofocus class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                <button type="button" wire:click="deleteConfirmed" class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                    <flux:icon icon="trash" variant="micro" /> Excluir
                </button>
            </div>
        </x-modal>
    @endif
</div>
