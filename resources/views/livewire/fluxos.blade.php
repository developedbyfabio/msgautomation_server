<div class="h-full overflow-y-auto">
    <div class="mx-auto max-w-4xl p-6 space-y-4">
        @if (! $flow)
            {{-- ===================== LISTA ===================== --}}
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">Fluxos (menus)</h1>
                <button type="button" wire:click="novoFluxo"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-3 py-2 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
                    <flux:icon icon="plus" variant="micro" /> Novo fluxo
                </button>
            </div>
            <p class="text-sm text-zinc-500">
                Menu numerado: gatilho de entrada -> envia o menu -> o contato responde 1, 2, 3... -> sub-menu
                ou resposta final. Enquanto a conversa esta no menu, ela tem prioridade sobre as regras.
            </p>

            <div class="rounded-xl border border-zinc-200 bg-white divide-y divide-zinc-100 dark:border-zinc-800 dark:bg-zinc-900 dark:divide-zinc-800">
                @forelse ($flows as $f)
                    @php $trigs = $f->triggerList(); @endphp
                    <div class="flex items-center gap-3 p-3" wire:key="f-{{ $f->id }}">
                        <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-zinc-500 dark:bg-zinc-800">
                            <flux:icon icon="rectangle-stack" variant="micro" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium">{{ $f->name }}</div>
                            <div class="truncate text-xs text-zinc-500">
                                {{ $f->nodes_count }} no(s) ·
                                {{ $f->scope === 'contatos' ? 'contatos especificos' : 'todos os aprovados' }} ·
                                gatilhos: {{ $trigs->pluck('value')->take(3)->implode(', ') ?: '(nenhum)' }}
                            </div>
                        </div>
                        <button type="button" wire:click="toggleFluxo({{ $f->id }})" @class([
                            'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
                            'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' => $f->enabled,
                            'bg-zinc-200 text-zinc-500 dark:bg-zinc-800' => ! $f->enabled,
                        ])>
                            <span @class(['size-1.5 rounded-full', 'bg-emerald-500' => $f->enabled, 'bg-zinc-400' => ! $f->enabled])></span>
                            {{ $f->enabled ? 'ON' : 'OFF' }}
                        </button>
                        <flux:dropdown position="bottom" align="end">
                            <button type="button" class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" aria-label="Acoes">
                                <flux:icon icon="ellipsis-vertical" variant="micro" />
                            </button>
                            <flux:menu>
                                <flux:menu.item wire:click="editar({{ $f->id }})" icon="pencil-square">Editar</flux:menu.item>
                                <flux:menu.separator />
                                <flux:menu.item wire:click="confirmDeleteFlow({{ $f->id }})" icon="trash" variant="danger">Excluir</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </div>
                @empty
                    <div class="flex flex-col items-center gap-2 p-10 text-center text-zinc-400">
                        <flux:icon icon="rectangle-stack" class="size-8" />
                        <p class="text-sm">Nenhum fluxo ainda. Crie o primeiro menu.</p>
                    </div>
                @endforelse
            </div>
        @else
            {{-- ===================== EDITOR ===================== --}}
            <div class="flex items-center justify-between gap-3">
                <button type="button" wire:click="voltar" class="inline-flex items-center gap-1 text-sm text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200">
                    <flux:icon icon="arrow-left" variant="micro" /> Fluxos
                </button>
                <button type="button" wire:click="toggleFluxo({{ $flow->id }})" @class([
                    'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium',
                    'bg-emerald-600 text-white hover:bg-emerald-700' => ! $flow->enabled,
                    'bg-zinc-200 text-zinc-700 hover:bg-zinc-300 dark:bg-zinc-700 dark:text-zinc-200' => $flow->enabled,
                ])>
                    <flux:icon icon="{{ $flow->enabled ? 'pause' : 'play' }}" variant="micro" />
                    {{ $flow->enabled ? 'Desligar fluxo' : 'Ligar fluxo' }}
                </button>
            </div>

            {{-- CONFIG --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-5 space-y-4 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="text-xs font-semibold uppercase tracking-wide text-zinc-400">Configuracao</div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium">Nome</label>
                        <input type="text" wire:model="name" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                        @error('name') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium">Timeout (s de inatividade)</label>
                        <input type="number" min="60" wire:model="timeout_seconds" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                        @error('timeout_seconds') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium">Mensagem de "opcao invalida" (opcional)</label>
                    <input type="text" wire:model="invalid_message" placeholder="Opcao invalida. Escolha uma das opcoes." class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                </div>

                {{-- Escopo --}}
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-zinc-400">Escopo</label>
                    <div class="flex items-center gap-4 text-sm">
                        <label class="inline-flex items-center gap-1.5"><input type="radio" wire:model.live="scope" value="global"> Todos os Aprovados</label>
                        <label class="inline-flex items-center gap-1.5"><input type="radio" wire:model.live="scope" value="contatos"> Contatos Especificos</label>
                    </div>
                    @if ($scope === 'contatos')
                        <div class="mt-2 rounded-lg border border-zinc-200 dark:border-zinc-700">
                            <div class="flex items-center gap-2 border-b border-zinc-100 p-2 dark:border-zinc-800">
                                <input type="search" wire:model.live.debounce.250ms="scopeSearch" placeholder="Buscar contato..." class="w-full rounded-lg border border-zinc-300 bg-white px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                                <span class="shrink-0 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">{{ count($scopeContactIds) }} sel.</span>
                            </div>
                            <div class="max-h-40 overflow-y-auto p-1">
                                @forelse ($contacts as $c)
                                    <label wire:key="fc-{{ $c->id }}" class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                        <input type="checkbox" value="{{ $c->id }}" wire:model.live="scopeContactIds" class="rounded border-zinc-300 dark:border-zinc-700">
                                        <span class="min-w-0 flex-1 truncate">{{ $c->push_name ?: \Illuminate\Support\Str::before($c->remote_jid, '@') }}</span>
                                    </label>
                                @empty
                                    <p class="px-2 py-3 text-center text-xs text-zinc-400">Nenhum contato.</p>
                                @endforelse
                            </div>
                        </div>
                        @error('scopeContactIds') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    @endif
                </div>

                {{-- Gatilhos de entrada --}}
                <div>
                    <div class="mb-1 flex items-center justify-between">
                        <label class="text-xs font-semibold uppercase tracking-wide text-zinc-400">Gatilhos de entrada</label>
                        <button type="button" wire:click="addTrigger" class="inline-flex items-center gap-1 text-xs text-emerald-600 hover:underline"><flux:icon icon="plus" variant="micro" /> gatilho</button>
                    </div>
                    <div class="space-y-2">
                        @foreach ($triggers as $i => $t)
                            <div wire:key="ftrg-{{ $i }}" class="flex flex-wrap items-start gap-2 sm:flex-nowrap">
                                <select wire:model.live="triggers.{{ $i }}.type" class="w-28 shrink-0 rounded-lg border border-zinc-300 bg-white px-2 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                                    <option value="contains">Contem</option>
                                    <option value="exact">Mensagem exata</option>
                                    <option value="starts_with">Comeca com</option>
                                    <option value="regex">Regex (avancado)</option>
                                </select>
                                <div class="min-w-0 flex-1">
                                    <input type="text" wire:model="triggers.{{ $i }}.value" placeholder="ex.: menu" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                                    @error("triggers.{$i}.value") <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                                </div>
                                <button type="button" wire:click="removeTrigger({{ $i }})" @disabled(count($triggers) <= 1) class="mt-2.5 text-zinc-400 hover:text-red-500 disabled:opacity-30" aria-label="Remover"><flux:icon icon="x-mark" variant="micro" /></button>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div>
                    <button type="button" wire:click="salvarConfig" class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-zinc-900">
                        <flux:icon icon="check" variant="micro" /> Salvar configuracao
                    </button>
                </div>
            </div>

            {{-- ARVORE DE NOS --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-5 space-y-3 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="text-xs font-semibold uppercase tracking-wide text-zinc-400">Menu (nos e opcoes)</div>
                @foreach ($tree as $row)
                    @php $node = $row['node']; $isRoot = (int) $flow->root_node_id === (int) $node->id; @endphp
                    <div wire:key="node-{{ $node->id }}" class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" style="margin-left: {{ min($row['depth'], 6) * 18 }}px">
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2 text-xs">
                                <span class="rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-[10px] text-zinc-500 dark:bg-zinc-800">no #{{ $node->id }}</span>
                                @if ($isRoot) <span class="rounded-full bg-sky-100 px-1.5 py-0.5 text-[10px] text-sky-700 dark:bg-sky-950 dark:text-sky-300">raiz</span> @endif
                                <select wire:model="nodeKind.{{ $node->id }}" class="rounded-lg border border-zinc-300 bg-white px-1.5 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800">
                                    <option value="menu">menu (espera opcao)</option>
                                    <option value="final">final (encerra)</option>
                                </select>
                            </div>
                            <div class="flex items-center gap-2">
                                @if (! empty($secretNames))
                                    <flux:dropdown position="bottom" align="end">
                                        <button type="button" class="inline-flex items-center gap-1 text-xs text-emerald-600 hover:underline"><flux:icon icon="key" variant="micro" /> senha</button>
                                        <flux:menu>
                                            @foreach ($secretNames as $sn)
                                                <flux:menu.item wire:click="inserirSenhaNo({{ $node->id }}, '{{ $sn }}')">{{ $sn }}</flux:menu.item>
                                            @endforeach
                                        </flux:menu>
                                    </flux:dropdown>
                                @endif
                                <button type="button" wire:click="salvarNo({{ $node->id }})" class="rounded-lg bg-zinc-900 px-2 py-1 text-xs font-medium text-white dark:bg-white dark:text-zinc-900">Salvar no</button>
                                @unless ($isRoot)
                                    <button type="button" wire:click="removerNo({{ $node->id }})" class="text-zinc-400 hover:text-red-500" aria-label="Remover no"><flux:icon icon="trash" variant="micro" /></button>
                                @endunless
                            </div>
                        </div>
                        <textarea wire:model="nodeMsg.{{ $node->id }}" rows="2" placeholder="Mensagem do no (placeholders e {senha:...} permitidos)" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"></textarea>

                        @if (($nodeKind[$node->id] ?? $node->kind) !== 'final')
                            <div class="mt-2 space-y-1.5">
                                @foreach ($node->options as $opt)
                                    <div wire:key="opt-{{ $opt->id }}" class="flex flex-wrap items-center gap-2 rounded-md bg-zinc-50 p-2 text-sm dark:bg-zinc-800/50 sm:flex-nowrap">
                                        <input type="text" wire:model="optBuf.{{ $opt->id }}.input" placeholder="1" class="w-14 shrink-0 rounded border border-zinc-300 bg-white px-2 py-1 text-center text-sm dark:border-zinc-700 dark:bg-zinc-900">
                                        <input type="text" wire:model="optBuf.{{ $opt->id }}.label" placeholder="Rotulo (ex.: 1 - Suporte)" class="min-w-0 flex-1 rounded border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                                        <select wire:change="definirDestino({{ $opt->id }}, $event.target.value)" class="w-40 shrink-0 rounded border border-zinc-300 bg-white px-1.5 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-900">
                                            <option value="" @selected(! $opt->next_node_id)>vai para...</option>
                                            <optgroup label="No existente">
                                                @foreach ($tree as $r2)
                                                    @if ((int) $r2['node']->id !== (int) $node->id)
                                                        <option value="{{ $r2['node']->id }}" @selected((int) $opt->next_node_id === (int) $r2['node']->id)>no #{{ $r2['node']->id }} ({{ $r2['node']->kind }})</option>
                                                    @endif
                                                @endforeach
                                            </optgroup>
                                            <optgroup label="Criar e ligar">
                                                <option value="novo_menu">+ novo sub-menu</option>
                                                <option value="novo_final">+ nova resposta final</option>
                                            </optgroup>
                                        </select>
                                        <button type="button" wire:click="salvarOpcao({{ $opt->id }})" class="shrink-0 rounded bg-zinc-200 px-2 py-1 text-xs dark:bg-zinc-700">ok</button>
                                        <button type="button" wire:click="removerOpcao({{ $opt->id }})" class="shrink-0 text-zinc-400 hover:text-red-500" aria-label="Remover opcao"><flux:icon icon="x-mark" variant="micro" /></button>
                                    </div>
                                @endforeach
                                <button type="button" wire:click="addOpcao({{ $node->id }})" class="inline-flex items-center gap-1 text-xs text-emerald-600 hover:underline"><flux:icon icon="plus" variant="micro" /> opcao</button>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- PREVIEW --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-400">Visualizacao</div>
                <div class="space-y-0.5 font-mono text-xs">
                    @foreach ($tree as $row)
                        @php $n = $row['node']; @endphp
                        <div style="margin-left: {{ min($row['depth'], 6) * 16 }}px" class="text-zinc-600 dark:text-zinc-300">
                            <span class="text-zinc-400">{{ $n->kind === 'final' ? 'fim' : 'menu' }}:</span>
                            {{ \Illuminate\Support\Str::limit(strip_tags($n->message), 50) }}
                            @foreach ($n->options as $opt)
                                <div style="margin-left: 16px" class="text-zinc-400">{{ $opt->input }} -> {{ $opt->label ?: '(sem rotulo)' }} {{ $opt->next_node_id ? '[no #' . $opt->next_node_id . ']' : '[sem destino]' }}</div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- MODAL: excluir fluxo --}}
        @if ($deleting)
            <x-modal wireClose="cancelDeleteFlow" title="Excluir fluxo">
                <p class="text-sm text-zinc-600 dark:text-zinc-300">Excluir o fluxo <strong>"{{ $deleting->name }}"</strong>? Apaga nos, opcoes e sessoes. Nao pode ser desfeito.</p>
                <div class="flex justify-end gap-2 pt-4">
                    <button type="button" wire:click="cancelDeleteFlow" data-autofocus class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                    <button type="button" wire:click="deleteFlowConfirmed" class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700"><flux:icon icon="trash" variant="micro" /> Excluir</button>
                </div>
            </x-modal>
        @endif
    </div>
</div>
