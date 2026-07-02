<div class="h-full overflow-y-auto">
    <div class="mx-auto max-w-4xl p-6 space-y-4">
        <div class="flex items-center justify-between gap-3">
            <h1 class="text-xl font-semibold">Contatos / Agenda</h1>
            <div class="flex items-center gap-2">
                <div class="relative w-64">
                    <span class="pointer-events-none absolute inset-y-0 left-2 flex items-center text-zinc-400">
                        <flux:icon icon="magnifying-glass" variant="micro" />
                    </span>
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Buscar nome ou numero..."
                        class="w-full rounded-lg border border-zinc-300 bg-white py-2 pl-8 pr-3 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                </div>
                <button type="button" wire:click="openTags"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 px-3 py-2 text-sm font-medium hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">
                    <flux:icon icon="tag" variant="micro" /> Tags
                </button>
            </div>
        </div>

        <p class="text-sm text-zinc-500">
            Agenda auto-populada. <strong>on</strong> = responde (sob allowlist); <strong>off</strong> = nunca;
            <strong>default</strong> = segue a politica.
        </p>

        <div class="rounded-xl border border-zinc-200 bg-white divide-y divide-zinc-100 dark:border-zinc-800 dark:bg-zinc-900 dark:divide-zinc-800">
            @forelse ($contacts as $c)
                <div class="flex items-center gap-3 p-3" wire:key="c-{{ $c->id }}">
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-zinc-200 text-sm font-medium text-zinc-600 dark:bg-zinc-700 dark:text-zinc-200">
                        {{ mb_strtoupper(mb_substr($c->push_name ?: '?', 0, 1)) }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="truncate font-medium">{{ $c->push_name ?: 'Sem nome' }}</div>
                        <div class="truncate text-xs text-zinc-500">{{ $c->remote_jid }}</div>
                        @if ($c->notes)
                            <div class="truncate text-xs text-zinc-400">{{ $c->notes }}</div>
                        @endif
                        @if ($c->tags->isNotEmpty())
                            <div class="mt-1 flex flex-wrap gap-1">
                                @foreach ($c->tags->take(4) as $t)
                                    <x-tag-chip :color="$t->color" small wire:key="lt-{{ $c->id }}-{{ $t->id }}">{{ $t->name }}</x-tag-chip>
                                @endforeach
                                @if ($c->tags->count() > 4)
                                    <span class="text-[10px] text-zinc-400">+{{ $c->tags->count() - 4 }}</span>
                                @endif
                            </div>
                        @endif
                    </div>

                    <span @class([
                        'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
                        'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' => $c->auto_reply_mode === 'on',
                        'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' => $c->auto_reply_mode === 'off',
                        'bg-zinc-200 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' => ! in_array($c->auto_reply_mode, ['on', 'off'], true),
                    ])>
                        @if ($c->auto_reply_mode === 'on') <span class="size-1.5 rounded-full bg-emerald-500"></span> @endif
                        {{ $c->auto_reply_mode }}
                    </span>

                    @if ($c->proactive_opt_in)
                        <span class="inline-flex items-center gap-1 rounded-full bg-rose-100 px-2 py-0.5 text-xs font-medium text-rose-700 dark:bg-rose-950 dark:text-rose-300" title="Opt-in de proativas ativo (consentimento registrado)">
                            <flux:icon icon="megaphone" variant="micro" class="size-3" /> opt-in
                        </span>
                    @endif

                    @if ($c->ai_enabled && $c->ai_mode !== 'rules_only')
                        <span class="inline-flex items-center gap-1 rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300" title="IA ligada ({{ $c->ai_mode }})">
                            <flux:icon icon="sparkles" variant="micro" class="size-3" /> IA
                        </span>
                    @endif

                    <flux:dropdown position="bottom" align="end">
                        <button type="button" class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" aria-label="Acoes">
                            <flux:icon icon="ellipsis-vertical" variant="micro" />
                        </button>
                        <flux:menu>
                            <flux:menu.item wire:click="setMode({{ $c->id }}, 'on')" icon="check-circle">Aprovar (on)</flux:menu.item>
                            <flux:menu.item wire:click="setMode({{ $c->id }}, 'default')" icon="minus-circle">Default</flux:menu.item>
                            <flux:menu.item wire:click="startEdit({{ $c->id }})" icon="pencil-square">Editar</flux:menu.item>
                            <flux:menu.separator />
                            <flux:menu.item wire:click="confirmMute({{ $c->id }})" icon="no-symbol" variant="danger">Silenciar (off)</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>
            @empty
                <div class="flex flex-col items-center gap-2 p-10 text-center text-zinc-400">
                    <flux:icon icon="users" class="size-8" />
                    <p class="text-sm">Nenhum contato ainda. Eles aparecem conforme mensagens chegam.</p>
                </div>
            @endforelse
        </div>
    </div>

    {{-- MODAL: editar contato --}}
    @if ($editing)
        <x-modal wireClose="cancelEdit" title="Editar contato">
            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-medium mb-1">Nome</label>
                    <input type="text" wire:model="editName" data-autofocus class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Notas</label>
                    <textarea wire:model="editNotes" rows="3" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"></textarea>
                </div>

                {{-- T-1: tags do contato (componente reutilizavel) --}}
                <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <livewire:contact-tags :contact-id="$editing->id" :key="'ctags-'.$editing->id" />
                </div>

                {{-- P-1: opt-in de proativas (consentimento explicito, trilha auditada) --}}
                <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <label class="inline-flex items-start gap-2 text-sm font-medium">
                        <input type="checkbox" wire:model="editProactiveOptIn" class="mt-0.5 rounded border-zinc-300 dark:border-zinc-700">
                        <span>Aceita mensagens proativas (opt-in)
                            <span class="block text-[11px] font-normal text-zinc-400">
                                Consentimento pra RECEBER mensagens que o sistema inicia (lembrete/follow-up).
                                Cada mudanca fica registrada com data e origem. O contato revoga sozinho
                                mandando a palavra de opt-out. Nada dispara sem campanha aprovada.
                            </span>
                        </span>
                    </label>
                </div>

                {{-- IA por contato (Camada 3) --}}
                <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <label class="inline-flex items-center gap-2 text-sm font-medium">
                        <input type="checkbox" wire:model.live="editAiEnabled" class="rounded border-zinc-300 dark:border-zinc-700">
                        <flux:icon icon="sparkles" variant="micro" class="text-indigo-500" /> IA para este contato
                    </label>
                    <p class="mt-1 text-[11px] text-zinc-400">
                        So age quando nenhuma regra/fluxo casa, e so se o kill switch da IA estiver ligado
                        (Configuracoes). Nasce desligada.
                    </p>
                    @if ($editAiEnabled)
                        <div class="mt-2">
                            <label class="mb-1 flex items-center gap-1 text-xs font-medium">
                                Modo
                                <x-info-tip text="Conhecimento libera a IA a responder este contato com a base de conhecimento (pagina Conhecimento): primeiro tenta casar suas regras; se nenhuma casar, responde SO com o conteudo da base (low/medium) fundamentado — high nunca vai a IA nem e respondido direto. Sem fundamento, silencia." />
                            </label>
                            <select wire:model="editAiMode" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                                <option value="intencao">Intencao — casa suas regras e usa a resposta da regra</option>
                                <option value="aprovacao">Aprovacao — so sugere, nunca envia sozinho</option>
                                <option value="conhecimento">Conhecimento — regras por IA + base de conhecimento</option>
                                <option value="rules_only">Rules only — IA nao age</option>
                            </select>
                            <p class="mt-1 text-[11px] text-zinc-400">Recomendado: Intencao (conservador). "Conhecimento" tambem responde pela base (so entradas low/medium permitidas; nunca inventa).</p>
                        </div>
                    @endif
                </div>

                <div class="flex justify-end gap-2 pt-1">
                    <button type="button" wire:click="cancelEdit" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                    <button type="button" wire:click="saveEdit" class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-zinc-900">
                        <flux:icon icon="check" variant="micro" /> Salvar
                    </button>
                </div>
            </div>
        </x-modal>
    @endif

    {{-- MODAL: confirmar silenciar --}}
    @if ($muting)
        <x-modal wireClose="cancelMute" title="Silenciar contato">
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                Silenciar <strong>{{ $muting->push_name ?: $muting->remote_jid }}</strong>? O robo nunca vai
                auto-responder este contato (off). Voce ainda pode mandar mensagem manual.
            </p>
            <div class="flex justify-end gap-2 pt-4">
                <button type="button" wire:click="cancelMute" data-autofocus class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                <button type="button" wire:click="muteConfirmed" class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                    <flux:icon icon="no-symbol" variant="micro" /> Silenciar
                </button>
            </div>
        </x-modal>
    @endif

    {{-- T-1 MODAL: gerenciar tags --}}
    @if ($showTags && ! $deletingTag)
        <x-modal wireClose="closeTags" title="Gerenciar tags" maxWidth="lg">
            <div class="space-y-2">
                <p class="text-xs text-zinc-500">Renomear/trocar cor vale na hora. Excluir mostra onde a tag e usada e pede confirmacao.</p>
                @forelse ($tagList as $tag)
                    <div class="flex items-center gap-2" wire:key="tg-{{ $tag->id }}">
                        <x-tag-chip :color="$tagColors[$tag->id] ?? $tag->color" small>{{ $tagNames[$tag->id] ?? $tag->name }}</x-tag-chip>
                        <input type="text" wire:model="tagNames.{{ $tag->id }}" maxlength="40"
                            class="min-w-0 flex-1 rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                        <select wire:model.live="tagColors.{{ $tag->id }}" class="w-28 shrink-0 rounded-lg border border-zinc-300 bg-white px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                            @foreach (\App\Models\Tag::COLORS as $cor)
                                <option value="{{ $cor }}">{{ $cor }}</option>
                            @endforeach
                        </select>
                        <button type="button" wire:click="confirmDeleteTag({{ $tag->id }})" class="shrink-0 text-zinc-400 hover:text-red-500" aria-label="Excluir tag">
                            <flux:icon icon="trash" variant="micro" />
                        </button>
                        @error('tagNames.' . $tag->id) <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                @empty
                    <p class="py-3 text-center text-xs text-zinc-400">Nenhuma tag ainda. Crie pela primeira vez no painel de um contato.</p>
                @endforelse
            </div>
            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="closeTags" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                    <button type="button" wire:click="saveTags" class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-zinc-900">
                        <flux:icon icon="check" variant="micro" /> Salvar
                    </button>
                </div>
            </x-slot:footer>
        </x-modal>
    @endif

    {{-- T-1 MODAL: confirmar exclusao de tag (com uso) --}}
    @if ($deletingTag)
        @php $uso = $this->tagUsage($deletingTag->id); @endphp
        <x-modal wireClose="cancelDeleteTag" title="Excluir tag">
            <div class="space-y-2 text-sm text-zinc-600 dark:text-zinc-300">
                <p>Excluir a tag <x-tag-chip :color="$deletingTag->color" small>{{ $deletingTag->name }}</x-tag-chip>?</p>
                <ul class="list-disc pl-5 text-xs">
                    <li>{{ $uso['contatos'] }} contato(s) perdem a tag na hora.</li>
                    <li>{{ $uso['regras'] }} regra(s) e {{ $uso['fluxos'] }} fluxo(s) usam a tag como ESCOPO — ficam <strong>sem alcance</strong> ate voce ajustar.</li>
                    <li>{{ $uso['kanban'] }} regra(s) de movimento do Kanban usam a tag — ficam inertes ate ajuste.</li>
                </ul>
            </div>
            <div class="flex justify-end gap-2 pt-4">
                <button type="button" wire:click="cancelDeleteTag" data-autofocus class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                <button type="button" wire:click="deleteTagConfirmed" class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                    <flux:icon icon="trash" variant="micro" /> Excluir
                </button>
            </div>
        </x-modal>
    @endif
</div>
