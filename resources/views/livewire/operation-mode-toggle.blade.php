{{-- Fatia 2 — toggle do Modo de Operacao (server-side, por conta). Visual coerente
     com o botao do dark toggle ao lado; wire:loading evita duplo clique. Tooltip
     NEUTRO (sem promessa comportamental — o robo so le a flag na Fatia 4). --}}
<button type="button" wire:click="toggle" wire:loading.attr="disabled" wire:target="toggle"
    title="Modo de operacao da conta (atual: {{ $this->label() }})"
    aria-label="Alternar modo de operacao (atual: {{ $this->label() }})"
    class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-800 disabled:opacity-60 dark:hover:bg-zinc-800 dark:hover:text-zinc-200">
    <flux:icon :icon="$auto ? 'bolt' : 'user'" variant="micro" wire:loading.remove wire:target="toggle" />
    <flux:icon icon="arrow-path" variant="micro" class="animate-spin" wire:loading wire:target="toggle" />
    <span>{{ $this->label() }}</span>
</button>
