@props(['text'])

{{-- Icone "i" com tooltip (hover + foco) — acessivel via aria-label. Flux tooltip
     e free. type=button pra nunca submeter o form que o envolve. --}}
<flux:tooltip :content="$text">
    <button type="button" tabindex="0" aria-label="{{ $text }}"
        class="inline-flex text-zinc-400 transition hover:text-zinc-600 focus:text-zinc-600 focus:outline-none dark:hover:text-zinc-200 dark:focus:text-zinc-200">
        <flux:icon icon="information-circle" variant="micro" class="size-4" />
    </button>
</flux:tooltip>
