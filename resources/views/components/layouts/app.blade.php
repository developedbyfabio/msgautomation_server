@php
    $robo = \App\Models\AutoReplySetting::query()->value('enabled');
    // Fatia 3: contador de pendencias da IA (escopado pela conta-ancora, como as telas).
    $navAccountId = (int) \App\Models\Account::query()->oldest('id')->value('id');
    $expDias = (int) config('ai.approval_expire_days', 7);
    $pendencias = \App\Models\PendingApproval::query()
        ->where('account_id', $navAccountId)
        ->where('status', 'pending')
        ->when($expDias > 0, fn ($q) => $q->where('created_at', '>=', now()->subDays($expDias)))
        ->count();
    $nav = [
        ['conversas', 'Conversas', 'chat-bubble-left-right', 0],
        ['contatos', 'Contatos', 'users', 0],
        ['senhas', 'Senhas', 'key', 0],
        ['regras', 'Regras', 'bolt', 0],
        ['fluxos', 'Fluxos', 'rectangle-stack', 0],
        ['conhecimento', 'Conhecimento', 'book-open', 0],
        ['revisao', 'Revisao', 'inbox', $pendencias],
        ['configuracoes', 'Configuracoes', 'cog-6-tooth', 0],
    ];
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'msgautomation' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="h-screen flex flex-col bg-zinc-100 text-zinc-900 dark:bg-zinc-950 dark:text-zinc-100 antialiased">
    <header class="shrink-0 border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex items-center gap-4 px-4 h-12">
            <span class="font-semibold tracking-tight">msgautomation</span>
            <nav class="flex items-center gap-1 text-sm">
                @foreach ($nav as [$route, $label, $icon, $badge])
                    <a href="{{ route($route) }}"
                       wire:navigate
                       @class([
                           'flex items-center gap-1.5 px-3 py-1.5 rounded-md transition',
                           'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => request()->routeIs($route),
                           'hover:bg-zinc-100 dark:hover:bg-zinc-800' => ! request()->routeIs($route),
                       ])>
                        <flux:icon :icon="$icon" variant="micro" />
                        <span>{{ $label }}</span>
                        @if ($badge > 0)
                            <span class="inline-flex min-w-4 items-center justify-center rounded-full bg-amber-500 px-1 text-[10px] font-semibold text-white">{{ $badge > 99 ? '99+' : $badge }}</span>
                        @endif
                    </a>
                @endforeach
            </nav>
            <div class="ml-auto flex items-center gap-3 text-xs">
                <livewire:status-conexao />
                <span class="text-zinc-400">|</span>
                <span class="text-zinc-500">Robo:</span>
                @if ($robo)
                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 font-medium text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                        <span class="size-1.5 rounded-full bg-emerald-500"></span> ON
                    </span>
                @else
                    <span class="inline-flex items-center gap-1 rounded-full bg-zinc-200 px-2 py-0.5 font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                        <span class="size-1.5 rounded-full bg-zinc-400"></span> OFF
                    </span>
                @endif
                <span class="text-zinc-400">|</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-800 dark:hover:bg-zinc-800 dark:hover:text-zinc-200" title="Sair">
                        <flux:icon icon="arrow-right-start-on-rectangle" variant="micro" /> Sair
                    </button>
                </form>
            </div>
        </div>
    </header>

    @unless ($robo)
        {{-- Banner proeminente: robo OFF nao responde ninguem (kill switch). --}}
        <div class="shrink-0 border-b border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
            <div class="flex items-center gap-2">
                <flux:icon icon="exclamation-triangle" variant="micro" class="shrink-0" />
                <span><strong>Robo desligado</strong> — nao responde ninguem automaticamente. A auto-resposta so reage a mensagens <strong>recebidas</strong> de contatos aprovados (on); nunca as mensagens que voce mesmo envia. Para ligar: <a href="{{ route('configuracoes') }}" wire:navigate class="font-medium underline">Configuracoes</a>.</span>
            </div>
        </div>
    @endunless

    <main class="flex-1 min-h-0 overflow-hidden">
        {{ $slot }}
    </main>

    {{-- Toasts globais (Alpine ouve eventos 'toast' despachados pelos componentes Livewire). --}}
    <div
        x-data="{ toasts: [] }"
        @toast.window="
            const id = Date.now() + Math.random();
            toasts.push({ id, message: $event.detail.message ?? $event.detail[0]?.message, type: $event.detail.type ?? $event.detail[0]?.type ?? 'success' });
            setTimeout(() => toasts = toasts.filter(t => t.id !== id), 3500)
        "
        class="fixed bottom-4 right-4 z-[60] flex flex-col gap-2"
    >
        <template x-for="t in toasts" :key="t.id">
            <div
                x-transition
                class="rounded-lg px-4 py-2 text-sm text-white shadow-lg"
                :class="t.type === 'error' ? 'bg-red-600' : 'bg-zinc-900 dark:bg-zinc-700'"
                x-text="t.message"
            ></div>
        </template>
    </div>

    @fluxScripts
</body>
</html>
