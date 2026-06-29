@props([
    'wireClose' => null,
    'title' => null,
    'maxWidth' => 'md',   // md | lg | xl | 2xl
    'footer' => null,     // slot opcional: rodape FIXO (botoes sempre visiveis)
])

@php
    $maxW = [
        'md' => 'max-w-md',
        'lg' => 'max-w-lg',
        'xl' => 'max-w-xl',
        '2xl' => 'max-w-2xl',
    ][$maxWidth] ?? 'max-w-md';
@endphp

{{-- Modal reutilizavel (Alpine + Tailwind — Flux modal e Pro, evitado).
     Visibilidade controlada pelo componente pai via @if. ESC e clique no backdrop
     chamam o metodo Livewire de fechar. Foco vai pro elemento [data-autofocus].
     S1: altura maxima 85vh; cabecalho e rodape FIXOS, corpo com scroll interno —
     com muitos gatilhos o Salvar (rodape) continua visivel e o modal nao vaza. --}}
<div
    x-data
    @keydown.escape.window="$wire.{{ $wireClose }}()"
    class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center"
    role="dialog"
    aria-modal="true"
    @if ($title) aria-label="{{ $title }}" @endif
>
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="$wire.{{ $wireClose }}()"></div>

    <div
        x-init="$nextTick(() => $el.querySelector('[data-autofocus]')?.focus())"
        class="relative z-10 flex max-h-[85vh] w-full {{ $maxW }} flex-col overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-zinc-900"
    >
        @if ($title)
            <h2 class="shrink-0 border-b border-zinc-100 px-5 py-4 text-lg font-semibold dark:border-zinc-800">{{ $title }}</h2>
        @endif

        {{-- Corpo rolavel --}}
        <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
            {{ $slot }}
        </div>

        {{-- Rodape fixo (opcional) --}}
        @isset($footer)
            <div class="shrink-0 border-t border-zinc-100 px-5 py-3 dark:border-zinc-800">
                {{ $footer }}
            </div>
        @endisset
    </div>
</div>
