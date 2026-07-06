<div class="w-full max-w-sm px-6">
    <div class="mb-6 text-center">
        <div class="mx-auto mb-3 flex size-12 items-center justify-center rounded-2xl bg-emerald-600 text-white">
            <flux:icon icon="chat-bubble-left-right" />
        </div>
        {{-- Fatia 21: texto FORA do card fica sobre o overlay escuro do fundo —
             claro nos dois temas (contraste garantido sobre qualquer imagem). --}}
        <h1 class="text-lg font-semibold text-white drop-shadow-sm">msgautomation</h1>
        <p class="text-sm text-zinc-200 drop-shadow-sm">Acesso restrito. Entre para continuar.</p>
    </div>

    <form wire:submit="login" class="space-y-4 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div>
            <label class="mb-1 block text-xs font-medium">E-mail</label>
            <input type="email" wire:model="email" autocomplete="username" autofocus
                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800">
            @error('email') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="mb-1 block text-xs font-medium">Senha</label>
            <input type="password" wire:model="password" autocomplete="current-password"
                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800">
            @error('password') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
        </div>

        <label class="inline-flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
            <input type="checkbox" wire:model="remember" class="rounded border-zinc-300 dark:border-zinc-700"> Manter conectado
        </label>

        <button type="submit" wire:loading.attr="disabled" wire:target="login"
            class="flex w-full items-center justify-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-60">
            <flux:icon icon="arrow-path" variant="micro" class="animate-spin" wire:loading wire:target="login" />
            Entrar
        </button>
    </form>
</div>
