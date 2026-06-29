@php
    $robo = \App\Models\AutoReplySetting::query()->value('enabled');
    $nav = [
        ['conversas', 'Conversas'],
        ['contatos', 'Contatos'],
        ['regras', 'Regras'],
        ['configuracoes', 'Configuracoes'],
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
                @foreach ($nav as [$route, $label])
                    <a href="{{ route($route) }}"
                       wire:navigate
                       @class([
                           'px-3 py-1.5 rounded-md transition',
                           'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => request()->routeIs($route),
                           'hover:bg-zinc-100 dark:hover:bg-zinc-800' => ! request()->routeIs($route),
                       ])>{{ $label }}</a>
                @endforeach
            </nav>
            <div class="ml-auto flex items-center gap-2 text-xs">
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
            </div>
        </div>
    </header>

    <main class="flex-1 min-h-0 overflow-hidden">
        {{ $slot }}
    </main>

    @fluxScripts
</body>
</html>
