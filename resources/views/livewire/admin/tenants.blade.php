<div class="h-full overflow-y-auto">
    <div class="mx-auto max-w-4xl p-6 space-y-4">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Administracao de tenants</h1>
                <p class="text-sm text-zinc-500">Contas de clientes da plataforma. Acesso restrito ao administrador.</p>
            </div>
            <button type="button" wire:click="openCreate"
                class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                <flux:icon icon="plus" variant="micro" /> Novo tenant
            </button>
        </div>

        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <table class="w-full text-sm">
                <thead class="border-b border-zinc-100 text-left text-xs uppercase tracking-wide text-zinc-400 dark:border-zinc-800">
                    <tr>
                        <th class="px-4 py-2 font-medium">Conta</th>
                        <th class="px-4 py-2 font-medium">Slug</th>
                        <th class="px-4 py-2 font-medium">Usuarios</th>
                        <th class="px-4 py-2 font-medium">Criada em</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($tenants as $t)
                        <tr wire:key="tenant-{{ $t['id'] }}">
                            <td class="px-4 py-2 font-medium">{{ $t['name'] }}</td>
                            <td class="px-4 py-2 text-zinc-500">{{ $t['slug'] }}</td>
                            <td class="px-4 py-2 text-zinc-500">{{ $t['users_count'] }}</td>
                            <td class="px-4 py-2 text-zinc-500">{{ $t['created_at']?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</td>
                            <td class="px-4 py-2 text-right">
                                <button type="button" wire:click="openEdit({{ $t['id'] }})"
                                    class="inline-flex items-center gap-1 rounded-lg border border-zinc-300 px-2.5 py-1 text-xs hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">
                                    <flux:icon icon="pencil-square" variant="micro" /> Editar
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-6 text-center text-zinc-400">Nenhum tenant ainda.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- MODAL: criar tenant (conta + owner) --}}
    @if ($showCreate)
        <x-modal wireClose="cancelCreate" title="Novo tenant">
            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-medium mb-1">Nome da conta</label>
                    <input type="text" wire:model="accountName" data-autofocus placeholder="Ex.: Padaria do Ze"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    @error('accountName') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>

                <div class="rounded-lg border border-zinc-200 p-3 space-y-3 dark:border-zinc-700">
                    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-400">Owner da conta</div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Nome</label>
                        <input type="text" wire:model="ownerName" placeholder="Nome do responsavel"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                        @error('ownerName') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Email</label>
                        <input type="email" wire:model="ownerEmail" placeholder="owner@cliente.com"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                        @error('ownerEmail') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Senha (minimo 10)</label>
                        <input type="password" wire:model="ownerPassword" autocomplete="new-password"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                        @error('ownerPassword') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-1">
                    <button type="button" wire:click="cancelCreate" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                    <button type="button" wire:click="criar" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                        <flux:icon icon="check" variant="micro" /> Criar tenant
                    </button>
                </div>
            </div>
        </x-modal>
    @endif

    {{-- MODAL: editar tenant (conta + usuarios) --}}
    @if ($editing)
        <x-modal wireClose="closeEdit" title="Editar tenant" maxWidth="2xl">
            <div class="space-y-5">
                {{-- Conta: nome editavel; slug (instancia) imutavel --}}
                <div class="space-y-2">
                    <div>
                        <label class="block text-xs font-medium mb-1">Nome da conta</label>
                        <div class="flex gap-2">
                            <input type="text" wire:model="editName" class="flex-1 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                            <button type="button" wire:click="salvarConta" class="rounded-lg bg-zinc-900 px-3 py-2 text-sm font-medium text-white dark:bg-white dark:text-zinc-900">Salvar</button>
                        </div>
                        @error('editName') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1 text-zinc-400">Slug / instancia (imutavel)</label>
                        <input type="text" readonly value="{{ $editSlug }}" class="w-full rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800">
                    </div>
                </div>

                {{-- Usuarios do tenant --}}
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <div class="border-b border-zinc-100 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:border-zinc-700">Usuarios</div>
                    <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($editUsers as $u)
                            <div class="px-3 py-2 space-y-2" wire:key="eu-{{ $u['id'] }}">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="min-w-0">
                                        <div class="truncate text-sm font-medium">{{ $u['name'] }}</div>
                                        <div class="truncate text-xs text-zinc-500">{{ $u['email'] }}
                                            <span class="ml-1 rounded bg-zinc-100 px-1 text-[10px] dark:bg-zinc-800">{{ $u['role'] }}</span>
                                        </div>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-1">
                                        <button type="button" wire:click="alternarOwner({{ $u['id'] }})" class="rounded border border-zinc-300 px-2 py-1 text-[11px] hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">{{ $u['role'] === 'owner' ? 'Tornar operador' : 'Tornar owner' }}</button>
                                        <button type="button" wire:click="$set('rowUserId', {{ $u['id'] }})" class="rounded border border-zinc-300 px-2 py-1 text-[11px] hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">Email/Senha</button>
                                        <button type="button" wire:click="removerUsuario({{ $u['id'] }})" class="rounded border border-red-300 px-2 py-1 text-[11px] text-red-600 hover:bg-red-50 dark:border-red-800 dark:hover:bg-red-950">Remover</button>
                                    </div>
                                </div>
                                @if ($rowUserId === $u['id'])
                                    <div class="grid gap-2 rounded-lg bg-zinc-50 p-2 sm:grid-cols-2 dark:bg-zinc-800/50">
                                        <div>
                                            <input type="email" wire:model="rowEmail" placeholder="novo email" class="w-full rounded border border-zinc-300 bg-white px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800">
                                            @error('rowEmail') <p class="mt-0.5 text-[11px] text-red-500">{{ $message }}</p> @enderror
                                            <button type="button" wire:click="editarEmail({{ $u['id'] }})" class="mt-1 rounded bg-zinc-900 px-2 py-1 text-[11px] text-white dark:bg-white dark:text-zinc-900">Salvar email</button>
                                        </div>
                                        <div>
                                            <input type="password" wire:model="rowPassword" placeholder="nova senha (min 10)" autocomplete="new-password" class="w-full rounded border border-zinc-300 bg-white px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800">
                                            @error('rowPassword') <p class="mt-0.5 text-[11px] text-red-500">{{ $message }}</p> @enderror
                                            <button type="button" wire:click="resetarSenha({{ $u['id'] }})" class="mt-1 rounded bg-zinc-900 px-2 py-1 text-[11px] text-white dark:bg-white dark:text-zinc-900">Redefinir senha</button>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @empty
                            <div class="px-3 py-4 text-center text-xs text-zinc-400">Nenhum usuario. Adicione um owner abaixo.</div>
                        @endforelse
                    </div>

                    {{-- Adicionar usuario --}}
                    <div class="border-t border-zinc-100 p-3 space-y-2 dark:border-zinc-700">
                        <div class="text-xs font-semibold uppercase tracking-wide text-zinc-400">Adicionar usuario</div>
                        <div class="grid gap-2 sm:grid-cols-3">
                            <div>
                                <input type="text" wire:model="nuName" placeholder="Nome" class="w-full rounded border border-zinc-300 bg-white px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                                @error('nuName') <p class="mt-0.5 text-[11px] text-red-500">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <input type="email" wire:model="nuEmail" placeholder="email" class="w-full rounded border border-zinc-300 bg-white px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                                @error('nuEmail') <p class="mt-0.5 text-[11px] text-red-500">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <input type="password" wire:model="nuPassword" placeholder="senha (min 10)" autocomplete="new-password" class="w-full rounded border border-zinc-300 bg-white px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                                @error('nuPassword') <p class="mt-0.5 text-[11px] text-red-500">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <div class="flex items-center justify-between">
                            <label class="inline-flex items-center gap-1.5 text-sm">
                                <input type="checkbox" wire:model="nuOwner" class="rounded border-zinc-300 dark:border-zinc-700"> Owner
                            </label>
                            <button type="button" wire:click="adicionarUsuario" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700">
                                <flux:icon icon="plus" variant="micro" /> Adicionar
                            </button>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="button" wire:click="closeEdit" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Fechar</button>
                </div>
            </div>
        </x-modal>
    @endif
</div>
