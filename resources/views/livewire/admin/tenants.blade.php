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
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($tenants as $t)
                        <tr wire:key="tenant-{{ $t['id'] }}">
                            <td class="px-4 py-2 font-medium">{{ $t['name'] }}</td>
                            <td class="px-4 py-2 text-zinc-500">{{ $t['slug'] }}</td>
                            <td class="px-4 py-2 text-zinc-500">{{ $t['users_count'] }}</td>
                            <td class="px-4 py-2 text-zinc-500">{{ $t['created_at']?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-6 text-center text-zinc-400">Nenhum tenant ainda.</td></tr>
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
</div>
