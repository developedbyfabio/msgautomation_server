<x-layouts.auth>
    <x-slot:title>Confirme seu e-mail — msgautomation</x-slot:title>

    <div class="w-full max-w-sm px-6">
        <div class="mb-6 text-center">
            <div class="mx-auto mb-3 flex size-12 items-center justify-center rounded-2xl bg-emerald-600 text-white">
                <flux:icon icon="envelope" />
            </div>
            <h1 class="text-lg font-semibold text-white drop-shadow-sm">Confirme seu e-mail</h1>
            <p class="text-sm text-zinc-200 drop-shadow-sm">Falta so um passo para ativar sua conta.</p>
        </div>

        <div class="space-y-4 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                Enviamos um link de confirmacao para
                <strong>{{ auth()->user()->email }}</strong>. Abra o e-mail e clique no link para
                comecar seu teste gratis.
            </p>

            @if (session('status') === 'verification-link-sent')
                <p class="rounded-lg bg-emerald-50 px-3 py-2 text-xs text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                    Link reenviado. Confira sua caixa de entrada (e a pasta de spam).
                </p>
            @endif

            {{-- POST do Fortify (verification.send, throttle 6/min). --}}
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <button type="submit"
                    class="flex w-full items-center justify-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                    <flux:icon icon="paper-airplane" variant="micro" /> Reenviar e-mail
                </button>
            </form>

            <form method="POST" action="{{ route('logout') }}" class="text-center">
                @csrf
                <button type="submit" class="text-xs text-zinc-500 hover:underline dark:text-zinc-400">
                    Sair e entrar com outra conta
                </button>
            </form>
        </div>
    </div>
</x-layouts.auth>
