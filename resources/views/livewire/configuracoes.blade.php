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
                    <div class="flex items-center gap-1 font-semibold">
                        Autoresponder (kill switch)
                        <x-info-tip text="Liga/desliga o robo responder sozinho. OFF = nao responde ninguem." />
                    </div>
                    <p class="text-sm text-zinc-500 mt-1 max-w-md">
                        Liga/desliga a resposta automatica na hora. Ligado, o robo passa a responder
                        contatos aprovados que casam uma regra (respeitando janela e tetos).
                        <strong>Atencao:</strong> isto faz o sistema enviar mensagens sozinho.
                    </p>
                </div>
                <button type="button" wire:click="requestKillSwitch"
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
                <div class="mb-1 flex items-center gap-1">
                    <label class="text-sm font-medium">Politica de resposta</label>
                    <x-info-tip text="Quem o robo pode responder. allowlist = so contatos aprovados (on). todos = todos, menos os silenciados (off)." />
                </div>
                <select wire:model="reply_policy" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    <option value="allowlist">allowlist — responde so contatos aprovados (on)</option>
                    <option value="all">all — responde todos, exceto off</option>
                </select>
                @error('reply_policy') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <div class="mb-1 flex items-center gap-1">
                        <label class="text-sm font-medium">Janela inicio</label>
                        <x-info-tip text="Faixa de horario (Sao Paulo) em que o robo pode responder. Fora dela, fica calado." />
                    </div>
                    <input type="time" wire:model="window_start" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    @error('window_start') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <div class="mb-1 flex items-center gap-1">
                        <label class="text-sm font-medium">Janela fim</label>
                        <x-info-tip text="Faixa de horario (Sao Paulo) em que o robo pode responder. Fora dela, fica calado." />
                    </div>
                    <input type="time" wire:model="window_end" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    @error('window_end') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-3 gap-4">
                @foreach ([
                    ['min_interval_seconds', 'Intervalo min (s)', 'Tempo minimo, em segundos, entre dois envios quaisquer do robo. Espaca os disparos pra nao parecer robo.'],
                    ['per_minute_cap', 'Teto / minuto', 'Maximo de respostas automaticas por minuto, no total. Atingiu, segura ate o minuto virar.'],
                    ['per_day_cap', 'Teto / dia', 'Maximo de respostas automaticas por dia, no total. Atingiu, para ate o dia seguinte.'],
                    ['contact_rate_seconds', 'Rate por contato (s)', 'Tempo minimo, em segundos, entre duas respostas pro MESMO contato. Evita responder a mesma pessoa repetidamente. 1800 = 30 min.'],
                    ['delay_min_seconds', 'Delay min (s)', 'O robo espera um tempo aleatorio entre delay min e max (segundos) antes de responder, pra parecer humano e nao responder na hora.'],
                    ['delay_max_seconds', 'Delay max (s)', 'O robo espera um tempo aleatorio entre delay min e max (segundos) antes de responder, pra parecer humano e nao responder na hora.'],
                ] as [$field, $label, $tip])
                    <div>
                        <div class="mb-1 flex items-center gap-1">
                            <label class="text-sm font-medium">{{ $label }}</label>
                            <x-info-tip :text="$tip" />
                        </div>
                        <input type="number" min="0" wire:model="{{ $field }}" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                        @error($field) <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>

            <div class="flex items-center gap-6">
                <div class="inline-flex items-center gap-1">
                    <label class="inline-flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="skip_groups" class="rounded border-zinc-300 dark:border-zinc-700"> Pular grupos
                    </label>
                    <x-info-tip text="Se marcado, o robo ignora mensagens de grupo. So responde conversa individual." />
                </div>
                <div class="inline-flex items-center gap-1">
                    <label class="inline-flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="warmup_enabled" class="rounded border-zinc-300 dark:border-zinc-700"> Aquecimento
                    </label>
                    <x-info-tip text="Modo de volume crescente pra numero novo (sobe devagar). Deixe desligado se o numero ja e estabelecido." />
                </div>
            </div>

            <div class="pt-2">
                <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
                    <flux:icon icon="check" variant="micro" /> Salvar freios
                </button>
            </div>
        </form>
    </div>

    {{-- MODAL: confirmar LIGAR o kill switch (desligar e instantaneo, sem modal) --}}
    @if ($confirmingEnable)
        <x-modal wireClose="cancelEnable" title="Ligar o autoresponder?">
            <div class="flex items-start gap-3">
                <div class="mt-0.5 text-amber-500"><flux:icon icon="exclamation-triangle" class="size-6" /></div>
                <p class="text-sm text-zinc-600 dark:text-zinc-300">
                    Isso ativa as <strong>respostas automaticas no numero pessoal</strong>. O robo passara a
                    responder contatos aprovados que casam uma regra (respeitando janela e tetos). Confirmar?
                </p>
            </div>
            <div class="flex justify-end gap-2 pt-4">
                <button type="button" wire:click="cancelEnable" data-autofocus class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                <button type="button" wire:click="enableConfirmed" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                    <flux:icon icon="bolt" variant="micro" /> Ligar robo
                </button>
            </div>
        </x-modal>
    @endif
</div>
