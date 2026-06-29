<div class="flex h-full items-center justify-center p-6" wire:poll.5s="poll">
    <div class="w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-8 text-center shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div class="mx-auto mb-4 flex size-12 items-center justify-center rounded-2xl bg-emerald-600 text-white">
            <flux:icon icon="qr-code" />
        </div>

        <h1 class="text-lg font-semibold">Conectar o WhatsApp</h1>

        @php
            [$cor, $rotulo] = match ($state) {
                'connecting' => ['text-amber-600', 'conectando...'],
                'verificando' => ['text-zinc-400', 'verificando...'],
                'open' => ['text-emerald-600', 'conectado'],
                default => ['text-red-500', 'desconectado'],
            };
        @endphp
        <p class="mt-1 text-sm {{ $cor }}">Estado: {{ $rotulo }}</p>

        <div class="mt-6 flex flex-col items-center gap-4">
            @if ($qrError)
                <div class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-600 dark:bg-red-950 dark:text-red-400">
                    {{ $qrError }}
                </div>
            @elseif ($qr)
                <img src="{{ $qr }}" alt="QR code do WhatsApp" class="size-64 rounded-xl bg-white p-2 ring-1 ring-zinc-200 dark:ring-zinc-700">
                <ol class="space-y-1 text-left text-xs text-zinc-500">
                    <li>1. Abra o WhatsApp no celular.</li>
                    <li>2. Aparelhos conectados &rarr; Conectar um aparelho.</li>
                    <li>3. Aponte a camera para este QR.</li>
                </ol>
            @else
                <div class="flex size-64 items-center justify-center rounded-xl bg-zinc-100 dark:bg-zinc-800">
                    <flux:icon icon="arrow-path" class="size-6 animate-spin text-zinc-400" />
                </div>
                <p class="text-xs text-zinc-500">Carregando QR...</p>
            @endif

            <p class="text-[11px] text-zinc-400">
                O QR expira rapido. Esta tela atualiza sozinha e segue pras conversas assim que conectar.
            </p>

            <button type="button" wire:click="gerarQr"
                class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 px-4 py-2 text-sm hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">
                <flux:icon icon="arrow-path" variant="micro" wire:loading.remove wire:target="gerarQr" />
                <flux:icon icon="arrow-path" variant="micro" class="animate-spin" wire:loading wire:target="gerarQr" />
                Gerar novo QR
            </button>
        </div>
    </div>
</div>
