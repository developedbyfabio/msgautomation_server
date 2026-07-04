@props([
    'qr' => null,
    'qrError' => null,
    'refreshAction' => 'gerarQr', // metodo Livewire que RE-BUSCA o QR (connect da instancia)
    'lifetime' => 40,             // s de vida do QR antes do auto-refresh (QR do WhatsApp rotaciona rapido)
])

{{-- Prompt 31 — painel UNICO de QR (reusado na /conexao e no modal de /conversas).
     Countdown client-side (Alpine): ao zerar, chama o refreshAction (novo connect,
     NAO reprovisiona). O wire:key keyado no QR reinicia o contador a cada QR novo. --}}
<div class="flex flex-col items-center gap-4"
    wire:key="qrpanel-{{ $qr ? md5($qr) : 'none' }}"
    x-data="{
        left: {{ (int) $lifetime }},
        timer: null,
        start() {
            this.left = {{ (int) $lifetime }};
            clearInterval(this.timer);
            this.timer = setInterval(() => {
                if (--this.left <= 0) { clearInterval(this.timer); $wire.{{ $refreshAction }}(); }
            }, 1000);
        },
        init() { if (@js((bool) $qr)) this.start(); },
        destroy() { clearInterval(this.timer); },
    }">
    @if ($qrError)
        <div class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-600 dark:bg-red-950 dark:text-red-400">{{ $qrError }}</div>
    @elseif ($qr)
        <img src="{{ $qr }}" alt="QR code do WhatsApp" class="size-64 rounded-xl bg-white p-2 ring-1 ring-zinc-200 dark:ring-zinc-700">
        <ol class="space-y-1 text-left text-xs text-zinc-500">
            <li>1. Abra o WhatsApp no celular.</li>
            <li>2. Aparelhos conectados &rarr; Conectar um aparelho.</li>
            <li>3. Aponte a camera para este QR.</li>
        </ol>
        {{-- Countdown regressivo; ao zerar, o Alpine ja pediu um QR novo. --}}
        <p class="text-xs text-zinc-400">
            <span x-show="left > 0">Expira em <span x-text="left" class="font-medium tabular-nums"></span>s — atualiza sozinho.</span>
            <span x-show="left <= 0" x-cloak>Atualizando o QR...</span>
        </p>
    @else
        <div class="flex size-64 items-center justify-center rounded-xl bg-zinc-100 dark:bg-zinc-800">
            <flux:icon icon="arrow-path" class="size-6 animate-spin text-zinc-400" />
        </div>
        <p class="text-xs text-zinc-500">Carregando QR...</p>
    @endif

    {{-- Atualizar: re-busca o QR da instancia existente (novo connect) e reinicia o contador.
         NAO reprovisiona/recria instancia. --}}
    <button type="button" wire:click="{{ $refreshAction }}" @click="start()"
        class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 px-4 py-2 text-sm hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">
        <flux:icon icon="arrow-path" variant="micro" wire:loading.remove wire:target="{{ $refreshAction }}" />
        <flux:icon icon="arrow-path" variant="micro" class="animate-spin" wire:loading wire:target="{{ $refreshAction }}" />
        Atualizar QR
    </button>
</div>
