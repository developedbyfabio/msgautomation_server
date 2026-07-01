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

        {{-- IA (Camada 3) — kill switch PROPRIO, separado do robo --}}
        <div @class([
            'rounded-xl border p-5',
            'border-indigo-300 bg-indigo-50 dark:border-indigo-800 dark:bg-indigo-950/40' => $ai_enabled,
            'border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900' => ! $ai_enabled,
        ])>
            <div class="flex items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-1 font-semibold">
                        <flux:icon icon="sparkles" variant="micro" class="text-indigo-500" />
                        IA classificadora
                        <x-info-tip text="Interruptor PROPRIO da IA (separado do robo). OFF = a IA nao age. Ligada, a IA so entra quando NENHUMA regra/fluxo casou: classifica a intencao e responde com a SUA resposta de regra, ou escala. Respeita todos os freios." />
                    </div>
                    <p class="mt-1 max-w-md text-sm text-zinc-500">
                        Ultimo recurso, so quando nenhuma regra casa. A IA <strong>nao inventa resposta</strong>:
                        casa uma regra sua (com "IA casa parecidas" ligada) e usa a resposta da regra.
                        Sensivel ou pouca certeza -> escala (nao envia). Precisa do robo ligado e do contato com IA ligada.
                    </p>
                </div>
                <button type="button" wire:click="requestAiSwitch"
                    @class([
                        'relative inline-flex h-7 w-12 shrink-0 items-center rounded-full transition',
                        'bg-indigo-500' => $ai_enabled,
                        'bg-zinc-300 dark:bg-zinc-700' => ! $ai_enabled,
                    ])
                    role="switch" aria-checked="{{ $ai_enabled ? 'true' : 'false' }}">
                    <span @class([
                        'inline-block size-5 transform rounded-full bg-white shadow transition',
                        'translate-x-6' => $ai_enabled,
                        'translate-x-1' => ! $ai_enabled,
                    ])></span>
                </button>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-x-6 gap-y-1 text-sm">
                <span class="font-medium">Estado: {{ $ai_enabled ? 'ON' : 'OFF (desligada)' }}</span>
                <span class="text-zinc-500">Limiar de confianca: <strong>{{ number_format($ai_confidence_threshold, 2) }}</strong> <span class="text-xs">(ajuste fino em breve)</span></span>
            </div>
            <div class="mt-2 text-xs text-zinc-500">
                Sempre exige aprovacao (nunca responde direto):
                @php $topicLabels = ['pagamento' => 'pagamento/PIX', 'dados_bancarios' => 'dados bancarios/senhas', 'compromissos' => 'compromissos', 'conteudo_high' => 'conteudo sensivel']; @endphp
                @forelse ($ai_approval_topics as $t)
                    <span class="ml-1 inline-flex rounded bg-zinc-100 px-1.5 py-0.5 dark:bg-zinc-800">{{ $topicLabels[$t] ?? $t }}</span>
                @empty
                    <span class="ml-1">nenhum configurado</span>
                @endforelse
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

            {{-- JANELA DE HORARIO (com toggle) --}}
            <div @class(['rounded-lg border border-zinc-200 p-3 dark:border-zinc-800', 'opacity-50' => ! $window_enabled])>
                <div class="mb-2 flex items-center justify-between">
                    <div class="flex items-center gap-1">
                        <label class="text-sm font-medium">Janela de horario</label>
                        <x-info-tip text="Faixa de horario (Sao Paulo) em que o robo pode responder. Fora dela, fica calado." />
                    </div>
                    <x-freio-toggle model="window_enabled" :enabled="$window_enabled" />
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="mb-1 block text-xs text-zinc-500">Inicio</label>
                        <input type="time" wire:model="window_start" @disabled(! $window_enabled) class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm disabled:cursor-not-allowed dark:border-zinc-700 dark:bg-zinc-800">
                        @error('window_start') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-zinc-500">Fim</label>
                        <input type="time" wire:model="window_end" @disabled(! $window_enabled) class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm disabled:cursor-not-allowed dark:border-zinc-700 dark:bg-zinc-800">
                        @error('window_end') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            {{-- FREIOS-THROTTLE com toggle liga/desliga --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @foreach ([
                    ['min_interval_seconds', 'min_interval_enabled', $min_interval_enabled, 'Intervalo min (s)', 'Tempo minimo, em segundos, entre dois envios quaisquer do robo. Espaca os disparos pra nao parecer robo.'],
                    ['per_minute_cap', 'per_minute_enabled', $per_minute_enabled, 'Teto / minuto', 'Maximo de respostas automaticas por minuto, no total. Atingiu, segura ate o minuto virar.'],
                    ['per_day_cap', 'per_day_enabled', $per_day_enabled, 'Teto / dia', 'Maximo de respostas automaticas por dia, no total. Atingiu, para ate o dia seguinte.'],
                    ['contact_rate_seconds', 'contact_rate_enabled', $contact_rate_enabled, 'Intervalo por contato (s)', 'Tempo minimo, em segundos, entre duas respostas pro MESMO contato. Evita responder a mesma pessoa repetidamente. 1800 = 30 min.'],
                ] as [$field, $toggleProp, $toggleVal, $label, $tip])
                    <div @class(['rounded-lg border border-zinc-200 p-3 dark:border-zinc-800', 'opacity-60' => ! $toggleVal])>
                        <div class="mb-1 flex items-center justify-between gap-2">
                            <span class="flex min-w-0 items-center gap-1">
                                <label class="truncate text-sm font-medium">{{ $label }}</label>
                                <x-info-tip :text="$tip" />
                            </span>
                            <x-freio-toggle :model="$toggleProp" :enabled="$toggleVal" />
                        </div>
                        <input type="number" min="0" wire:model="{{ $field }}" @disabled(! $toggleVal) class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm disabled:cursor-not-allowed dark:border-zinc-700 dark:bg-zinc-800">
                        @error($field) <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>

            {{-- DELAYS (timing humano — nao sao "bloqueio", sempre aplicam) --}}
            <div class="grid grid-cols-2 gap-4">
                @foreach ([
                    ['delay_min_seconds', 'Delay min (s)'],
                    ['delay_max_seconds', 'Delay max (s)'],
                ] as [$field, $label])
                    <div>
                        <div class="mb-1 flex items-center gap-1">
                            <label class="text-sm font-medium">{{ $label }}</label>
                            <x-info-tip text="O robo espera um tempo aleatorio entre delay min e max (segundos) antes de responder, pra parecer humano e nao responder na hora." />
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

            <p class="text-xs text-zinc-500">
                Estas sao suas protecoes anti-ban. Desligar da mais liberdade e mais risco de bloqueio do numero.
            </p>

            <div class="pt-2">
                <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
                    <flux:icon icon="check" variant="micro" /> Salvar freios
                </button>
            </div>
        </form>

        {{-- GUARDAS ESTRUTURAIS: sempre ativos, NAO desligaveis, mas visiveis (transparencia) --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-2 flex items-center gap-1.5 text-sm font-semibold">
                <flux:icon icon="lock-closed" variant="micro" class="text-zinc-400" />
                Protecoes de funcionamento (sempre ativas)
            </div>
            <p class="mb-3 text-xs text-zinc-500">Nao sao anti-ban e nao podem ser desligadas — garantem o funcionamento correto.</p>
            <ul class="space-y-2 text-sm">
                <li class="flex items-start gap-2">
                    <span class="mt-0.5 shrink-0 rounded-full bg-zinc-200 px-2 py-0.5 text-[10px] font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">sempre ativo</span>
                    <span><strong>fromMe</strong> — impede o robo de responder as proprias mensagens (evita loop infinito). Nao e anti-ban.</span>
                </li>
                <li class="flex items-start gap-2">
                    <span class="mt-0.5 shrink-0 rounded-full bg-zinc-200 px-2 py-0.5 text-[10px] font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">sempre ativo</span>
                    <span><strong>Idempotencia</strong> — impede responder a mesma mensagem mais de uma vez (evita duplicata). Nao e anti-ban.</span>
                </li>
            </ul>
        </div>
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

    {{-- MODAL: confirmar LIGAR a IA (desligar e instantaneo) --}}
    @if ($confirmingAiEnable)
        <x-modal wireClose="cancelAiEnable" title="Ligar a IA classificadora?">
            <div class="flex items-start gap-3">
                <div class="mt-0.5 text-indigo-500"><flux:icon icon="sparkles" class="size-6" /></div>
                <p class="text-sm text-zinc-600 dark:text-zinc-300">
                    Liga a IA como <strong>ultimo recurso</strong>: quando nenhuma regra/fluxo casar, a IA
                    classifica a intencao e pode responder com a sua resposta de regra (nos contatos com IA
                    ligada, passando por todos os freios). Ela <strong>nao inventa texto</strong> e escala o
                    que for sensivel. Confirmar?
                </p>
            </div>
            <div class="flex justify-end gap-2 pt-4">
                <button type="button" wire:click="cancelAiEnable" data-autofocus class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                <button type="button" wire:click="aiEnableConfirmed" class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                    <flux:icon icon="sparkles" variant="micro" /> Ligar IA
                </button>
            </div>
        </x-modal>
    @endif
</div>
