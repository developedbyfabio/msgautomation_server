<div class="h-full overflow-y-auto">
    <div class="mx-auto max-w-4xl p-6 space-y-4">
        <div class="flex items-center justify-between gap-3">
            <h1 class="text-xl font-semibold">Informacoes do negocio</h1>
            @if ($podeEditar)
            <button type="button" wire:click="novo"
                class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-3 py-2 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
                <flux:icon icon="plus" variant="micro" /> Nova entrada
            </button>
            @endif
        </div>

        <p class="text-sm text-zinc-500">
            Conteudo que a IA pode usar pra responder contatos em <strong>modo conhecimento</strong>
            (opt-in por contato, em Contatos). A IA so responde com o que esta aqui — nunca inventa.
            Sensibilidade <strong>high</strong> nunca vai ao modelo nem e respondida direto.
        </p>

        <div class="relative w-72">
            <span class="pointer-events-none absolute inset-y-0 left-2 flex items-center text-zinc-400">
                <flux:icon icon="magnifying-glass" variant="micro" />
            </span>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Buscar titulo ou conteudo..."
                class="w-full rounded-lg border border-zinc-300 bg-white py-2 pl-8 pr-3 text-sm dark:border-zinc-700 dark:bg-zinc-800">
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white divide-y divide-zinc-100 dark:border-zinc-800 dark:bg-zinc-900 dark:divide-zinc-800">
            @forelse ($entries as $k)
                <div class="flex items-start gap-3 p-3" wire:key="k-{{ $k->id }}">
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-indigo-500 dark:bg-indigo-950">
                        <flux:icon icon="book-open" variant="micro" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="truncate font-medium">{{ $k->title }}</span>
                            {{-- Badge de sensibilidade --}}
                            <span @class([
                                'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium',
                                'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' => $k->sensitivity === 'low',
                                'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300' => $k->sensitivity === 'medium',
                                'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' => $k->sensitivity === 'high',
                            ])>
                                @if ($k->sensitivity === 'high') <flux:icon icon="lock-closed" variant="micro" class="size-3" /> @endif
                                {{ $k->sensitivity }}
                            </span>
                        </div>
                        <div class="mt-0.5 truncate text-sm text-zinc-500">{{ \Illuminate\Support\Str::limit($k->content, 120) }}</div>
                        <div class="mt-1 flex flex-wrap items-center gap-1.5 text-[10px] text-zinc-400">
                            @if ($k->contacts->count() > 0)
                                <span class="inline-flex items-center gap-1 rounded bg-sky-100 px-1.5 py-0.5 text-sky-700 dark:bg-sky-950 dark:text-sky-300">
                                    <flux:icon icon="user" variant="micro" class="size-3" /> {{ $k->contacts->count() }} contato(s)
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 rounded bg-zinc-100 px-1.5 py-0.5 dark:bg-zinc-800">
                                    <flux:icon icon="globe-alt" variant="micro" class="size-3" /> todos com IA
                                </span>
                            @endif
                            @if ($k->sensitivity === 'high')
                                <span class="text-red-500 dark:text-red-400">nunca vai ao modelo; sempre escala pra revisao</span>
                            @endif
                        </div>
                    </div>

                    <span @class([
                        'mt-0.5 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
                        'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' => $k->active,
                        'bg-zinc-200 text-zinc-500 dark:bg-zinc-800' => ! $k->active,
                    ])>{{ $k->active ? 'ativa' : 'inativa' }}</span>

                    {{-- Fatia 23: operador ve; escrita so pra quem edita (gates = barreira real). --}}
                    @if ($podeEditar)
                    <flux:dropdown position="bottom" align="end">
                        <button type="button" class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" aria-label="Acoes">
                            <flux:icon icon="ellipsis-vertical" variant="micro" />
                        </button>
                        <flux:menu>
                            <flux:menu.item wire:click="edit({{ $k->id }})" icon="pencil-square">Editar</flux:menu.item>
                            <flux:menu.item wire:click="toggle({{ $k->id }})" icon="{{ $k->active ? 'pause' : 'play' }}">
                                {{ $k->active ? 'Desativar' : 'Ativar' }}
                            </flux:menu.item>
                            <flux:menu.separator />
                            <flux:menu.item wire:click="confirmDelete({{ $k->id }})" icon="trash" variant="danger">Excluir</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                    @endif
                </div>
            @empty
                <div class="flex flex-col items-center gap-2 p-10 text-center text-zinc-400">
                    <flux:icon icon="book-open" class="size-8" />
                    <p class="text-sm">{{ $search !== '' ? 'Nenhuma entrada encontrada.' : 'Base vazia. Cadastre a primeira entrada de conhecimento.' }}</p>
                </div>
            @endforelse
        </div>

        {{-- Fatia 14 — comecar com um modelo (instancia e abre no form) --}}
        @if ($podeEditar && ! empty($templates))
            <div>
                <h2 class="mb-1 text-sm font-medium text-zinc-700 dark:text-zinc-300">Comecar com um modelo</h2>
                <p class="mb-2 text-xs text-zinc-500">
                    Cria a entrada pronta na sua conta — preencha os textos entre [colchetes] com as suas informacoes.
                </p>
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($templates as $t)
                        <div class="flex flex-col rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900" wire:key="ktpl-{{ $t['key'] }}">
                            <div class="mb-1 flex items-center gap-1.5 font-medium">
                                <flux:icon icon="sparkles" variant="micro" class="text-zinc-400" />
                                {{ $t['name'] }}
                            </div>
                            <p class="mb-3 flex-1 text-xs text-zinc-500">{{ $t['description'] }}</p>
                            <button type="button" wire:click="usarTemplate('{{ $t['key'] }}')"
                                class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-zinc-300 px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800">
                                <flux:icon icon="plus" variant="micro" /> Usar modelo
                            </button>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    {{-- MODAL: criar/editar entrada --}}
    @if ($showForm)
        <x-modal wireClose="closeForm" title="{{ $editingId ? 'Editar entrada' : 'Nova entrada' }}" maxWidth="xl">
            <form id="knowledge-form" wire:submit="save" class="space-y-4">
                <div>
                    <label class="mb-1 block text-xs font-medium">Titulo</label>
                    <input type="text" wire:model="title" placeholder="ex.: Horario de funcionamento" data-autofocus
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    @error('title') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium">Conteudo</label>
                    <textarea wire:model="content" rows="5" placeholder="ex.: Atendemos de segunda a sexta, das 8h as 18h. Sabado ate 12h."
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"></textarea>
                    @error('content') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    <p class="mt-1 text-[11px] text-zinc-400">
                        A IA responde SO com o que esta aqui. Placeholders valem
                        (<code>{nome}</code>, <code>{saudacao}</code>, <code>{senha:nome}</code>) e sao resolvidos
                        no envio — nunca vao expandidos ao modelo. Resposta com <code>{senha:...}</code> nunca e
                        auto-enviada pela IA (escala pra voce).
                    </p>
                </div>

                <div>
                    <label class="mb-1 flex items-center gap-1 text-xs font-medium">
                        Sensibilidade
                        <x-info-tip text="low/medium: o conteudo vai ao modelo de IA (Gemini) e pode ser respondido automaticamente. high: NUNCA vai ao modelo nem e respondido direto — a mensagem escala pra sua revisao (fila na Fatia 3)." />
                    </label>
                    <select wire:model.live="sensitivity" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                        <option value="low">low — publico (vai ao modelo; pode responder direto)</option>
                        <option value="medium">medium — interno comum (vai ao modelo; pode responder direto)</option>
                        <option value="high">high — sensivel (NUNCA vai ao modelo; sempre escala pra revisao)</option>
                    </select>
                    @error('sensitivity') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    @if ($sensitivity === 'high')
                        <p class="mt-1 rounded bg-red-50 px-2 py-1 text-[11px] text-red-700 dark:bg-red-950/50 dark:text-red-300">
                            Conteudo high fica SO no servidor: nao e enviado a IA e nunca e respondido
                            automaticamente. Quando a base nao resolver, a mensagem escala pra voce revisar.
                        </p>
                    @endif
                </div>

                <div>
                    <label class="mb-1 flex items-center gap-1 text-xs font-medium">
                        Contatos permitidos
                        <x-info-tip text="Vazio = disponivel pra qualquer contato com IA ligada em modo conhecimento. Marcando contatos, so eles podem receber respostas baseadas nesta entrada." />
                    </label>
                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <div class="flex items-center gap-2 border-b border-zinc-100 p-2 dark:border-zinc-800">
                            <div class="relative flex-1">
                                <span class="pointer-events-none absolute inset-y-0 left-2 flex items-center text-zinc-400">
                                    <flux:icon icon="magnifying-glass" variant="micro" />
                                </span>
                                <input type="search" wire:model.live.debounce.250ms="contactSearch" placeholder="Buscar nome ou numero..."
                                    class="w-full rounded-lg border border-zinc-300 bg-white py-1.5 pl-8 pr-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                            </div>
                            <span class="shrink-0 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                                {{ count($contactIds) > 0 ? count($contactIds) . ' selecionado(s)' : 'todos com IA' }}
                            </span>
                        </div>
                        <div class="max-h-48 overflow-y-auto p-1">
                            @forelse ($contacts as $c)
                                <label wire:key="kc-{{ $c->id }}" class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                    <input type="checkbox" value="{{ $c->id }}" wire:model.live="contactIds" class="rounded border-zinc-300 dark:border-zinc-700">
                                    <span class="min-w-0 flex-1 truncate">{{ $c->push_name ?: \Illuminate\Support\Str::before($c->remote_jid, '@') }}</span>
                                    <span class="shrink-0 text-xs text-zinc-400">{{ \Illuminate\Support\Str::before($c->remote_jid, '@') }}</span>
                                </label>
                            @empty
                                <p class="px-2 py-3 text-center text-xs text-zinc-400">{{ $contactSearch !== '' ? 'Nenhum contato encontrado.' : 'Nenhum contato na agenda ainda.' }}</p>
                            @endforelse
                        </div>
                    </div>
                    <p class="mt-1 text-[11px] text-zinc-400">Sem marcar ninguem, a entrada vale pra todos os contatos com IA em modo conhecimento.</p>
                </div>

                <label class="inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="active" class="rounded border-zinc-300 dark:border-zinc-700"> Ativa
                </label>
            </form>

            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="closeForm" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                    <button type="submit" form="knowledge-form" class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-zinc-900">
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
        <x-modal wireClose="cancelDelete" title="Excluir entrada">
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                Excluir a entrada <strong>"{{ $deleting->title }}"</strong>? A IA deixa de usa-la
                imediatamente. Se preferir pausar sem perder o texto, use "Desativar".
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
