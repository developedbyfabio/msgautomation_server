<div wire:init="refresh" wire:poll.15s="refresh" class="flex items-center gap-2">
    @php
        [$cor, $rotulo] = match ($state) {
            'open' => ['bg-emerald-500', 'conectado'],
            'connecting' => ['bg-amber-500', 'conectando'],
            'verificando' => ['bg-zinc-300 dark:bg-zinc-600', 'verificando'],
            default => ['bg-red-500', 'desconectado'],
        };
    @endphp

    <span class="inline-flex items-center gap-1.5">
        <span class="size-2 rounded-full {{ $cor }}"></span>
        <span class="text-zinc-500">{{ $rotulo }}</span>
    </span>

    @if (! in_array($state, ['open', 'verificando'], true))
        <button type="button" wire:click="abrirQr"
            class="inline-flex items-center gap-1 rounded-md border border-zinc-300 px-2 py-0.5 text-xs hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">
            <flux:icon icon="qr-code" variant="micro" />
            <span wire:loading.remove wire:target="abrirQr">Reconectar</span>
            <span wire:loading wire:target="abrirQr">...</span>
        </button>
    @endif

    @if ($showQr)
        <x-modal wireClose="fecharQr" title="Conectar numero (QR)">
            <div class="flex flex-col items-center gap-3">
                @if ($qrError)
                    <p class="text-sm text-red-600 dark:text-red-400">{{ $qrError }}</p>
                @elseif ($qr)
                    <img src="{{ $qr }}" alt="QR code" class="size-64 rounded-lg bg-white p-2">
                    <p class="text-center text-xs text-zinc-500">
                        WhatsApp -> Aparelhos conectados -> Conectar um aparelho. O QR expira rapido;
                        clique em Atualizar se precisar.
                    </p>
                @else
                    <p class="text-sm text-zinc-500">Carregando QR...</p>
                @endif

                <div class="flex w-full justify-end gap-2 pt-2">
                    <button type="button" wire:click="abrirQr"
                        class="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-700">Atualizar</button>
                    <button type="button" wire:click="fecharQr" data-autofocus
                        class="rounded-lg bg-zinc-900 px-3 py-1.5 text-sm font-medium text-white dark:bg-white dark:text-zinc-900">Fechar</button>
                </div>
            </div>
        </x-modal>
    @endif
</div>
