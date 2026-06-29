<div class="h-full overflow-y-auto">
    <div class="mx-auto max-w-4xl p-6 space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">Regras (automacoes)</h1>
            <button type="button" wire:click="novo"
                class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-3 py-2 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
                <flux:icon icon="plus" variant="micro" /> Nova regra
            </button>
        </div>

        <p class="text-sm text-zinc-500">
            Varios gatilhos levam a mesma regra; varias respostas variam (escolha aleatoria no envio,
            ajuda anti-ban). <strong>contains</strong> casa palavra inteira; acento/maiusculas ignorados.
            Primeira regra (de cima) que casa vence.
        </p>

        <div class="rounded-xl border border-zinc-200 bg-white divide-y divide-zinc-100 dark:border-zinc-800 dark:bg-zinc-900 dark:divide-zinc-800">
            @forelse ($rules as $rule)
                @php $trigs = $rule->triggerList(); $resps = $rule->responseList(); @endphp
                <div class="flex items-start gap-3 p-3" wire:key="r-{{ $rule->id }}">
                    <div class="flex flex-col pt-1 text-zinc-400">
                        <button type="button" wire:click="move({{ $rule->id }}, 'up')" class="hover:text-zinc-700 dark:hover:text-zinc-200" aria-label="Subir">
                            <flux:icon icon="chevron-up" variant="micro" />
                        </button>
                        <button type="button" wire:click="move({{ $rule->id }}, 'down')" class="hover:text-zinc-700 dark:hover:text-zinc-200" aria-label="Descer">
                            <flux:icon icon="chevron-down" variant="micro" />
                        </button>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-1.5">
                            @foreach ($trigs as $t)
                                <span class="inline-flex items-center gap-1 rounded-md bg-zinc-100 px-1.5 py-0.5 text-xs dark:bg-zinc-800">
                                    <span class="font-mono text-[10px] text-zinc-400">{{ $t['type'] }}</span>
                                    <span class="font-medium">{{ $t['value'] }}</span>
                                </span>
                            @endforeach
                        </div>
                        <div class="mt-1 flex items-start gap-1 text-sm text-zinc-500">
                            <span class="shrink-0">&rarr;</span>
                            <span class="min-w-0 truncate">{{ $resps->first() }}</span>
                            @if ($resps->count() > 1)
                                <span class="shrink-0 rounded-full bg-emerald-100 px-1.5 text-[10px] font-medium text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">+{{ $resps->count() - 1 }} resp.</span>
                            @endif
                        </div>
                    </div>

                    <span @class([
                        'mt-0.5 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
                        'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' => $rule->enabled,
                        'bg-zinc-200 text-zinc-500 dark:bg-zinc-800' => ! $rule->enabled,
                    ])>{{ $rule->enabled ? 'ativa' : 'inativa' }}</span>

                    <flux:dropdown position="bottom" align="end">
                        <button type="button" class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" aria-label="Acoes">
                            <flux:icon icon="ellipsis-vertical" variant="micro" />
                        </button>
                        <flux:menu>
                            <flux:menu.item wire:click="edit({{ $rule->id }})" icon="pencil-square">Editar</flux:menu.item>
                            <flux:menu.item wire:click="toggle({{ $rule->id }})" icon="{{ $rule->enabled ? 'pause' : 'play' }}">
                                {{ $rule->enabled ? 'Desativar' : 'Ativar' }}
                            </flux:menu.item>
                            <flux:menu.separator />
                            <flux:menu.item wire:click="confirmDelete({{ $rule->id }})" icon="trash" variant="danger">Excluir</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>
            @empty
                <div class="flex flex-col items-center gap-2 p-10 text-center text-zinc-400">
                    <flux:icon icon="bolt" class="size-8" />
                    <p class="text-sm">Nenhuma regra criada. Crie a primeira automacao.</p>
                </div>
            @endforelse
        </div>
    </div>

    {{-- MODAL: criar/editar regra (rico) --}}
    @if ($showForm)
        <x-modal wireClose="closeForm" title="{{ $editingId ? 'Editar regra' : 'Nova regra' }}" maxWidth="2xl">
            <form id="rule-form" wire:submit="save" class="space-y-4">
                {{-- GATILHOS --}}
                <div>
                    <div class="mb-1 flex items-center justify-between">
                        <label class="text-xs font-semibold uppercase tracking-wide text-zinc-400">Gatilhos</label>
                        <button type="button" wire:click="addTrigger" class="inline-flex items-center gap-1 text-xs text-emerald-600 hover:underline">
                            <flux:icon icon="plus" variant="micro" /> gatilho
                        </button>
                    </div>
                    <p class="mb-2 text-[11px] text-zinc-400">Qualquer gatilho que casar dispara a regra.</p>
                    <div class="space-y-2">
                        @foreach ($triggers as $i => $t)
                            <div wire:key="trg-{{ $i }}" class="flex items-start gap-2">
                                <select wire:model.live="triggers.{{ $i }}.type" class="w-32 shrink-0 rounded-lg border border-zinc-300 bg-white px-2 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                                    <option value="contains">contains</option>
                                    <option value="exact">exact</option>
                                    <option value="starts_with">starts_with</option>
                                    <option value="regex">regex</option>
                                </select>
                                <div class="min-w-0 flex-1">
                                    <input type="text" wire:model="triggers.{{ $i }}.value" placeholder="{{ $t['type'] === 'regex' ? 'ex.: ^pre[cç]o' : 'ex.: horario' }}"
                                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                                    @error("triggers.{$i}.value") <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                                    @if ($t['type'] === 'regex')
                                        <p class="mt-1 text-[11px] text-amber-600 dark:text-amber-400">
                                            <flux:icon icon="exclamation-triangle" variant="micro" class="inline size-3" />
                                            Regex avancado: validado e protegido, mas teste antes. Sem delimitadores; flags i+u aplicadas.
                                        </p>
                                    @endif
                                </div>
                                <button type="button" wire:click="removeTrigger({{ $i }})" @disabled(count($triggers) <= 1)
                                    class="mt-1.5 text-zinc-400 hover:text-red-500 disabled:opacity-30" aria-label="Remover gatilho">
                                    <flux:icon icon="x-mark" variant="micro" />
                                </button>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- RESPOSTAS --}}
                <div>
                    <div class="mb-1 flex items-center justify-between">
                        <label class="text-xs font-semibold uppercase tracking-wide text-zinc-400">Respostas</label>
                        <button type="button" wire:click="addResponse" class="inline-flex items-center gap-1 text-xs text-emerald-600 hover:underline">
                            <flux:icon icon="plus" variant="micro" /> resposta
                        </button>
                    </div>
                    <p class="mb-2 text-[11px] text-zinc-400">Com mais de uma, o robo sorteia qual enviar (varia a resposta, ajuda anti-ban).</p>
                    <div class="space-y-2">
                        @foreach ($responses as $i => $r)
                            <div wire:key="resp-{{ $i }}" class="flex items-start gap-2">
                                <div class="min-w-0 flex-1">
                                    <textarea wire:model="responses.{{ $i }}" rows="2" placeholder="ex.: {saudacao}, {nome}! Atendo das 8h as 18h."
                                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"></textarea>
                                    @error("responses.{$i}") <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                                </div>
                                <button type="button" wire:click="removeResponse({{ $i }})" @disabled(count($responses) <= 1)
                                    class="mt-1.5 text-zinc-400 hover:text-red-500 disabled:opacity-30" aria-label="Remover resposta">
                                    <flux:icon icon="x-mark" variant="micro" />
                                </button>
                            </div>
                        @endforeach
                    </div>
                    @error('responses') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>

                {{-- PLACEHOLDERS --}}
                <div class="rounded-lg bg-zinc-50 p-3 text-[11px] text-zinc-500 dark:bg-zinc-800/50">
                    <span class="font-medium text-zinc-600 dark:text-zinc-300">Placeholders (processados no envio):</span>
                    <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">{nome}</code> nome do contato ·
                    <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">{saudacao}</code> bom dia/tarde/noite ·
                    <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">{data}</code> ·
                    <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">{hora}</code>
                </div>

                <label class="inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="enabled" class="rounded border-zinc-300 dark:border-zinc-700"> Habilitada
                </label>
            </form>

            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="closeForm" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                    <button type="submit" form="rule-form" class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-zinc-900">
                        <flux:icon icon="check" variant="micro" wire:loading.remove wire:target="save" />
                        <flux:icon icon="arrow-path" variant="micro" class="animate-spin" wire:loading wire:target="save" />
                        Salvar
                    </button>
                </div>
            </x-slot:footer>
        </x-modal>
    @endif

    {{-- MODAL: confirmar exclusao --}}
    @if ($deleting)
        <x-modal wireClose="cancelDelete" title="Excluir regra">
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                Apagar a regra com gatilho <strong>"{{ $deleting->triggerList()->first()['value'] ?? '' }}"</strong>?
                Esta acao nao pode ser desfeita.
            </p>
            <div class="flex justify-end gap-2 pt-4">
                <button type="button" wire:click="cancelDelete" data-autofocus class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                <button type="button" wire:click="deleteConfirmed" class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                    <flux:icon icon="trash" variant="micro" /> Excluir
                </button>
            </div>
        </x-modal>
    @endif
</div>
