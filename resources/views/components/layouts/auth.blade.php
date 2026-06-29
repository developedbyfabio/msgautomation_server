<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Entrar — msgautomation' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="min-h-screen flex items-center justify-center bg-zinc-100 text-zinc-900 dark:bg-zinc-950 dark:text-zinc-100 antialiased">
    {{ $slot }}
    @fluxScripts
</body>
</html>
