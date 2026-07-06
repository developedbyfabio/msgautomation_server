<div class="h-full overflow-y-auto">
    <div class="mx-auto max-w-3xl p-6 space-y-4">
        <div class="flex items-center justify-between gap-3">
            <h1 class="text-xl font-semibold">Cofre de credenciais</h1>
            <button type="button" wire:click="novo"
                class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-3 py-2 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
                <flux:icon icon="plus" variant="micro" /> Nova credencial
            </button>
        </div>

        <div class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/50 dark:text-amber-300">
            <flux:icon icon="shield-check" variant="micro" class="inline size-3.5" />
            Cofre tecnico, separado do conteudo de atendimento: os valores ficam <strong>cifrados</strong>
            (chave dedicada) e so saem numa mensagem se VOCE usar o codigo <code>{senha:nome}</code> numa regra —
            e ai vao em texto pra quem disparar, por isso regras assim exigem <strong>contatos especificos</strong>.
            Acesse por <strong>tunel SSH ou HTTPS</strong> (a rede e HTTP).
        </div>

        <div class="relative w-72">
            <span class="pointer-events-none absolute inset-y-0 left-2 flex items-center text-zinc-400">
                <flux:icon icon="magnifying-glass" variant="micro" />
            </span>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Buscar por nome..."
                class="w-full rounded-lg border border-zinc-300 bg-white py-2 pl-8 pr-3 text-sm dark:border-zinc-700 dark:bg-zinc-800">
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white divide-y divide-zinc-100 dark:border-zinc-800 dark:bg-zinc-900 dark:divide-zinc-800">
            @forelse ($secrets as $s)
                <div class="flex items-center gap-3 p-3" wire:key="s-{{ $s->id }}">
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-zinc-500 dark:bg-zinc-800">
                        <flux:icon icon="key" variant="micro" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="truncate font-medium">{{ $s->nome }}</span>
                            @if ($s->categoria)
                                <span class="rounded bg-zinc-100 px-1.5 text-[10px] text-zinc-500 dark:bg-zinc-800">{{ $s->categoria }}</span>
                            @endif
                        </div>
                        {{-- Fatia 10 — botoes de revelar/ocultar com icone de olho (mesmo estilo dos
                             botoes compactos do header). A MECANICA nao muda: o valor SO entra no
                             HTML depois do confirmReveal (re-senha de login, server-side). --}}
                        <div class="flex items-center gap-2 font-mono text-sm text-zinc-500">
                            @if ($revealedId === $s->id)
                                <span class="select-all text-zinc-800 dark:text-zinc-100">{{ $revealedValue }}</span>
                                <button type="button" wire:click="hideReveal"
                                    aria-label="Ocultar valor" title="Ocultar valor"
                                    class="inline-flex items-center gap-1 rounded-md border border-zinc-300 px-2 py-0.5 font-sans text-xs text-zinc-600 hover:bg-zinc-100 hover:text-zinc-800 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                                    <flux:icon icon="eye-slash" variant="micro" /> Ocultar
                                </button>
                            @else
                                <span aria-hidden="true">••••••••</span>
                                <button type="button" wire:click="askReveal({{ $s->id }})"
                                    aria-label="Revelar valor" title="Revelar valor"
                                    class="inline-flex items-center gap-1 rounded-md border border-zinc-300 px-2 py-0.5 font-sans text-xs text-emerald-700 hover:border-emerald-300 hover:bg-emerald-50 dark:border-zinc-700 dark:text-emerald-400 dark:hover:bg-emerald-950"
                                    wire:loading.attr="disabled" wire:target="askReveal">
                                    <flux:icon icon="eye" variant="micro" /> Revelar
                                </button>
                            @endif
                        </div>
                        @if ($s->notes)
                            <div class="truncate text-xs text-zinc-400">{{ $s->notes }}</div>
                        @endif
                    </div>

                    <flux:dropdown position="bottom" align="end">
                        <button type="button" class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" aria-label="Acoes">
                            <flux:icon icon="ellipsis-vertical" variant="micro" />
                        </button>
                        <flux:menu>
                            <flux:menu.item wire:click="edit({{ $s->id }})" icon="pencil-square">Editar</flux:menu.item>
                            <flux:menu.separator />
                            <flux:menu.item wire:click="confirmDelete({{ $s->id }})" icon="trash" variant="danger">Excluir</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>
            @empty
                <div class="flex flex-col items-center gap-2 p-10 text-center text-zinc-400">
                    <flux:icon icon="key" class="size-8" />
                    <p class="text-sm">{{ $search !== '' ? 'Nenhuma credencial encontrada.' : 'Cofre vazio. Cadastre a primeira credencial.' }}</p>
                </div>
            @endforelse
        </div>
    </div>

    {{-- MODAL: criar/editar --}}
    @if ($showForm)
        <x-modal wireClose="closeForm" title="{{ $editingId ? 'Editar credencial' : 'Nova credencial' }}">
            <form id="secret-form" wire:submit="save" class="space-y-3">
                <div>
                    <label class="mb-1 block text-xs font-medium">Nome (referencia)</label>
                    <input type="text" wire:model="nome" placeholder="ex.: wifi_pais" data-autofocus
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    @error('nome') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    <p class="mt-1 text-[11px] text-zinc-400">Usado na regra como <code>{senha:nome}</code>. So o nome aparece; o valor fica cifrado.</p>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Valor {{ $editingId ? '(deixe em branco para manter)' : '' }}</label>
                    <input type="password" wire:model="valor" autocomplete="new-password"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    @error('valor') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium">Categoria (opcional)</label>
                        <input type="text" wire:model="categoria" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium">Notas (opcional)</label>
                        <input type="text" wire:model="notes" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    </div>
                </div>
            </form>

            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="closeForm" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                    <button type="submit" form="secret-form" class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-zinc-900">
                        <flux:icon icon="check" variant="micro" /> Salvar
                    </button>
                </div>
            </x-slot:footer>
        </x-modal>
    @endif

    {{-- MODAL: revelar (re-digitar senha de login) --}}
    @if ($revealingId)
        <x-modal wireClose="cancelReveal" title="Revelar credencial">
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                Por seguranca, confirme sua <strong>senha de login</strong> para revelar este valor.
            </p>
            <form id="reveal-form" wire:submit="confirmReveal" class="mt-3">
                <input type="password" wire:model="revealPassword" autocomplete="current-password" data-autofocus
                    placeholder="Senha de login"
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                @error('revealPassword') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </form>
            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="cancelReveal" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                    <button type="submit" form="reveal-form" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                        <flux:icon icon="eye" variant="micro" /> Revelar
                    </button>
                </div>
            </x-slot:footer>
        </x-modal>
    @endif

    {{-- MODAL: confirmar exclusao --}}
    @if ($deleting)
        <x-modal wireClose="cancelDelete" title="Excluir credencial">
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                Excluir a senha <strong>"{{ $deleting->nome }}"</strong>? Regras que usam <code>{senha:{{ $deleting->nome }}}</code> deixarao de resolver.
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
