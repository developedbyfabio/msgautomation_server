<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Entrar — msgautomation' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
{{-- Fatia 21 — casca das telas de AUTH (login + desafio 2FA; o painel autenticado
     usa app.blade.php e NAO e afetado). Sem barra de rolagem no mobile:
     100dvh com fallback 100vh (no iOS o 100vh conta a area SOB a barra de
     endereco e estoura — era a causa do scroll reportado). Fundo fundo.webp
     cover/center/no-repeat com FALLBACK na cor solida do tema (bg-zinc-*), e
     OVERLAY escuro translucido + blur leve pra legibilidade sobre qualquer
     imagem, nos dois temas. Card centralizado nos DOIS eixos por flex. --}}
<body class="bg-zinc-100 text-zinc-900 dark:bg-zinc-950 dark:text-zinc-100 antialiased">
    <div class="relative flex items-center justify-center bg-cover bg-center bg-no-repeat"
        style="min-height: 100vh; min-height: 100dvh; background-image: url('{{ asset('fundo.webp') }}');">
        {{-- Overlay de legibilidade (atras do card, sobre a imagem). --}}
        <div class="absolute inset-0 bg-zinc-950/45 backdrop-blur-[2px]" aria-hidden="true"></div>

        {{-- Conteudo: centralizado; py da folga em telas baixas sem gerar scroll
             no layout padrao (se a viewport for MENOR que o conteudo, a pagina
             cresce de forma controlada — nunca overflow acidental). --}}
        <div class="relative z-10 flex w-full items-center justify-center py-8">
            {{ $slot }}
        </div>
    </div>
    @fluxScripts
</body>
</html>
