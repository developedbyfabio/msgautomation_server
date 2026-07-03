<x-layouts.auth>
    <x-slot:title>Verificação em duas etapas — msgautomation</x-slot:title>

    <div class="w-full max-w-sm px-6" x-data="{ recovery: false }">
        <div class="mb-6 text-center">
            <div class="mx-auto mb-3 flex size-12 items-center justify-center rounded-2xl bg-emerald-600 text-white">
                <flux:icon icon="shield-check" />
            </div>
            <h1 class="text-lg font-semibold">Verificação em duas etapas</h1>
            <p class="text-sm text-zinc-500" x-show="!recovery">Digite o código de 6 dígitos do seu app autenticador.</p>
            <p class="text-sm text-zinc-500" x-show="recovery" x-cloak>Digite um dos seus códigos de recuperação.</p>
        </div>

        <form method="POST" action="{{ route('two-factor.login.store') }}"
            class="space-y-4 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            @csrf

            <div x-show="!recovery">
                <label class="mb-1 block text-xs font-medium">Código</label>
                <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-center text-lg tracking-[0.4em] focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800">
            </div>

            <div x-show="recovery" x-cloak>
                <label class="mb-1 block text-xs font-medium">Código de recuperação</label>
                <input type="text" name="recovery_code" autocomplete="off"
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800">
            </div>

            @error('code') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
            @error('recovery_code') <p class="text-xs text-red-500">{{ $message }}</p> @enderror

            <button type="submit"
                class="flex w-full items-center justify-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                Verificar
            </button>

            <button type="button" @click="recovery = !recovery"
                class="w-full text-center text-xs text-zinc-500 underline-offset-2 hover:underline">
                <span x-show="!recovery">Usar um código de recuperação</span>
                <span x-show="recovery" x-cloak>Usar o código do app autenticador</span>
            </button>
        </form>

        <p class="mt-4 text-center text-xs text-zinc-400">
            <a href="{{ route('login') }}" class="hover:underline">Voltar pro login</a>
        </p>
    </div>
</x-layouts.auth>
