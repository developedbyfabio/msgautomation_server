@props(['model', 'enabled' => true])

{{-- Switch liga/desliga de um freio (S2). Estado refletido por $enabled; wire:model.live
     atualiza na hora. Acessivel: o input cobre o switch e mantem o foco. --}}
<label class="inline-flex cursor-pointer select-none items-center gap-1.5 text-xs font-medium" title="Ligar/desligar este freio">
    <span @class(['transition', 'text-emerald-600 dark:text-emerald-400' => $enabled, 'text-zinc-400' => ! $enabled])>{{ $enabled ? 'Ligado' : 'Desligado' }}</span>
    <span @class(['relative inline-block h-4 w-7 shrink-0 rounded-full transition', 'bg-emerald-500' => $enabled, 'bg-zinc-300 dark:bg-zinc-700' => ! $enabled])>
        <input type="checkbox" wire:model.live="{{ $model }}" class="absolute inset-0 z-10 cursor-pointer opacity-0">
        <span @class(['absolute top-0.5 size-3 rounded-full bg-white shadow transition', 'left-3.5' => $enabled, 'left-0.5' => ! $enabled])></span>
    </span>
</label>
