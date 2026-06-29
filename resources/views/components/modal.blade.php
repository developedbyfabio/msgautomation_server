@props([
    'wireClose' => null,
    'title' => null,
])

{{-- Modal reutilizavel (Alpine + Tailwind — Flux modal e Pro, evitado).
     Visibilidade controlada pelo componente pai via @if. ESC e clique no backdrop
     chamam o metodo Livewire de fechar. Foco vai pro elemento [data-autofocus]. --}}
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
        class="relative z-10 w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-5 shadow-2xl dark:border-zinc-800 dark:bg-zinc-900"
    >
        @if ($title)
            <h2 class="mb-3 text-lg font-semibold">{{ $title }}</h2>
        @endif

        {{ $slot }}
    </div>
</div>
