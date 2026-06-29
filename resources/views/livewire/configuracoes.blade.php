<div class="h-full overflow-y-auto">
    <div class="mx-auto max-w-3xl p-6 space-y-6">
        <h1 class="text-xl font-semibold">Configuracoes / Freios</h1>

        {{-- KILL SWITCH --}}
        <div @class([
            'rounded-xl border p-5',
            'border-emerald-300 bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-950/40' => $enabled,
            'border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900' => ! $enabled,
        ])>
            <div class="flex items-center justify-between gap-4">
                <div>
                    <div class="font-semibold">Autoresponder (kill switch)</div>
                    <p class="text-sm text-zinc-500 mt-1 max-w-md">
                        Liga/desliga a resposta automatica na hora. Ligado, o robo passa a responder
                        contatos aprovados que casam uma regra (respeitando janela e tetos).
                        <strong>Atencao:</strong> isto faz o sistema enviar mensagens sozinho.
                    </p>
                </div>
                <button type="button" wire:click="toggleKillSwitch"
                    @class([
                        'relative inline-flex h-7 w-12 shrink-0 items-center rounded-full transition',
                        'bg-emerald-500' => $enabled,
                        'bg-zinc-300 dark:bg-zinc-700' => ! $enabled,
                    ])
                    role="switch" aria-checked="{{ $enabled ? 'true' : 'false' }}">
                    <span @class([
                        'inline-block size-5 transform rounded-full bg-white shadow transition',
                        'translate-x-6' => $enabled,
                        'translate-x-1' => ! $enabled,
                    ])></span>
                </button>
            </div>
            <div class="mt-3 text-sm font-medium">
                Estado: {{ $enabled ? 'ON (respondendo)' : 'OFF (dormente)' }}
            </div>
        </div>

        {{-- DEMAIS FREIOS --}}
        <form wire:submit="save" class="rounded-xl border border-zinc-200 bg-white p-5 space-y-5 dark:border-zinc-800 dark:bg-zinc-900">
            @if ($salvo)
                <div class="rounded-lg bg-emerald-100 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                    Configuracoes salvas.
                </div>
            @endif

            <div>
                <label class="block text-sm font-medium mb-1">Politica de resposta</label>
                <select wire:model="reply_policy" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    <option value="allowlist">allowlist — responde so contatos aprovados (on)</option>
                    <option value="all">all — responde todos, exceto off</option>
                </select>
                @error('reply_policy') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Janela inicio</label>
                    <input type="time" wire:model="window_start" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    @error('window_start') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Janela fim</label>
                    <input type="time" wire:model="window_end" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    @error('window_end') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-3 gap-4">
                @foreach ([
                    ['min_interval_seconds', 'Intervalo min (s)'],
                    ['per_minute_cap', 'Teto / minuto'],
                    ['per_day_cap', 'Teto / dia'],
                    ['contact_rate_seconds', 'Rate por contato (s)'],
                    ['delay_min_seconds', 'Delay min (s)'],
                    ['delay_max_seconds', 'Delay max (s)'],
                ] as [$field, $label])
                    <div>
                        <label class="block text-sm font-medium mb-1">{{ $label }}</label>
                        <input type="number" min="0" wire:model="{{ $field }}" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                        @error($field) <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>

            <div class="flex items-center gap-6">
                <label class="inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="skip_groups" class="rounded border-zinc-300 dark:border-zinc-700"> Pular grupos
                </label>
                <label class="inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="warmup_enabled" class="rounded border-zinc-300 dark:border-zinc-700"> Aquecimento
                </label>
            </div>

            <div class="pt-2">
                <button type="submit" class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
                    Salvar freios
                </button>
            </div>
        </form>
    </div>
</div>
