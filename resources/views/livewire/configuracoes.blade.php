<div class="h-full overflow-y-auto">
    <div class="mx-auto max-w-3xl p-6 space-y-6">
        <h1 class="text-xl font-semibold">Configuracoes / Freios</h1>

        {{-- CANAL (CH-1 badge; MT-2: estado + URL mascarada + origem das credenciais) --}}
        @if ($canal)
            <div class="space-y-2 rounded-xl border border-zinc-200 bg-white p-4 text-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <flux:icon icon="signal" variant="micro" class="text-zinc-400" />
                        <span class="font-medium">Canal:</span>
                        <span class="text-zinc-500">{{ $canal->instance }}</span>
                        <span @class([
                            'rounded px-1.5 py-0.5 text-[10px] font-medium',
                            'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' => $canal->status === 'connected',
                            'bg-zinc-200 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' => $canal->status !== 'connected',
                        ])>{{ $canal->status }}</span>
                    </div>
                    <span class="rounded bg-indigo-100 px-2 py-0.5 text-[11px] font-medium text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300"
                        title="Provedor deste canal. Outros provedores (WhatsApp Cloud API oficial) chegam nas fatias CH-2+.">
                        {{ ['evolution' => 'Evolution', 'cloud_api' => 'Cloud API'][$canal->provider] ?? $canal->provider }}
                    </span>
                </div>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-zinc-400">
                    <span>Webhook: <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">{{ $canalWebhookMascarado }}</code></span>
                    <span>Credenciais: {{ empty($canal->credentials) ? 'env (fallback — rode msg:channel:sync-env)' : 'no canal (cifradas)' }}</span>
                    <a href="{{ route('conexao') }}" wire:navigate class="underline">conexao / QR</a>
                </div>
            </div>
        @endif

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
            <div class="mt-3 text-sm font-medium">Estado: {{ $ai_enabled ? 'ON' : 'OFF (desligada)' }}</div>

            {{-- Fatia 4 — ajuste fino: limiar + temas de aprovacao (editaveis). --}}
            <div class="mt-3 space-y-3 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                <div>
                    <label class="mb-1 flex items-center gap-1 text-xs font-medium">
                        Limiar de confianca (0.50 a 0.95)
                        <x-info-tip text="Abaixo do limiar a IA nao responde sozinha: escala pra sua revisao. BAIXAR = a IA responde mais sozinha (mais risco de resposta errada). SUBIR = escala mais pra voce. Vale so pra decisoes futuras." />
                    </label>
                    <input type="number" wire:model="ai_confidence_threshold" min="0.50" max="0.95" step="0.05"
                        class="w-28 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    @error('ai_confidence_threshold') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 flex items-center gap-1 text-xs font-medium">
                        Temas que SEMPRE exigem aprovacao
                        <x-info-tip text="Nesses temas a IA nunca responde direto: escala pro /revisao. Desmarcar um tema LIBERA a IA a responder sozinha nesse assunto (pede confirmacao). Vale so pra decisoes futuras." />
                    </label>
                    <div class="flex flex-wrap gap-x-4 gap-y-1.5 text-sm">
                        @foreach (\App\Livewire\Configuracoes::AI_TOPIC_LABELS as $valor => $rotulo)
                            <label class="inline-flex items-center gap-1.5">
                                <input type="checkbox" value="{{ $valor }}" wire:model="ai_approval_topics" class="rounded border-zinc-300 dark:border-zinc-700">
                                {{ $rotulo }}
                            </label>
                        @endforeach
                    </div>
                </div>
                <button type="button" wire:click="saveAi"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-3 py-2 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
                    <flux:icon icon="check" variant="micro" /> Salvar IA
                </button>
            </div>
        </div>


        {{-- PROATIVAS (P-1) — kill switch PROPRIO + jaula (disparo real so na P-3) --}}
        <div @class([
            'rounded-xl border p-5',
            'border-rose-300 bg-rose-50 dark:border-rose-800 dark:bg-rose-950/40' => $proactive_enabled,
            'border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900' => ! $proactive_enabled,
        ])>
            <div class="flex items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-1 font-semibold">
                        <flux:icon icon="megaphone" variant="micro" class="text-rose-500" />
                        Mensagens proativas
                        <x-info-tip text="Mensagem PROATIVA = o sistema INICIA a conversa (follow-up, lembrete, reativacao). E o maior risco de bloqueio do WhatsApp — os tetos existem pra te proteger. So contatos com opt-in explicito recebem, e NADA dispara sem campanha aprovada por voce (P-2/P-3)." />
                    </div>
                    <p class="mt-1 max-w-md text-sm text-zinc-500">
                        Interruptor proprio (independente do robo e da IA). Nesta fase nenhum disparo existe:
                        ligar so arma os freios. Opt-out do contato: mandar "<strong>{{ $proactive_optout_word }}</strong>".
                    </p>
                </div>
                <button type="button" wire:click="requestProactiveSwitch"
                    @class([
                        'relative inline-flex h-7 w-12 shrink-0 items-center rounded-full transition',
                        'bg-rose-500' => $proactive_enabled,
                        'bg-zinc-300 dark:bg-zinc-700' => ! $proactive_enabled,
                    ])
                    role="switch" aria-checked="{{ $proactive_enabled ? 'true' : 'false' }}">
                    <span @class([
                        'inline-block size-5 transform rounded-full bg-white shadow transition',
                        'translate-x-6' => $proactive_enabled,
                        'translate-x-1' => ! $proactive_enabled,
                    ])></span>
                </button>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-x-6 gap-y-1 text-sm">
                <span class="font-medium">Estado: {{ $proactive_enabled ? 'ON' : 'OFF (desligadas)' }}</span>
                @php $consumoDia = app(\App\Whatsapp\Proactive\ProactiveGuard::class)->dayCount(app(\App\Tenancy\AccountContext::class)->id()); @endphp
                <span class="text-zinc-500">Consumo de hoje: <strong>{{ $consumoDia }}</strong> / {{ $proactive_daily_cap }} proativas</span>
            </div>

            <div class="mt-3 grid grid-cols-2 gap-3 border-t border-zinc-100 pt-3 sm:grid-cols-4 dark:border-zinc-800">
                <div>
                    <label class="mb-1 flex items-center gap-1 text-xs font-medium">Teto/dia (conta)
                        <x-info-tip text="Maximo de proativas por DIA na conta inteira. Padrao seguro: 20. Subir acima disso pede confirmacao." />
                    </label>
                    <input type="number" min="1" max="200" wire:model="proactive_daily_cap"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    @error('proactive_daily_cap') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 flex items-center gap-1 text-xs font-medium">Por contato/semana
                        <x-info-tip text="Quantas proativas UM contato pode receber por semana (todas as campanhas somadas). Padrao seguro: 1." />
                    </label>
                    <input type="number" min="1" max="7" wire:model="proactive_per_contact_weekly_cap"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    @error('proactive_per_contact_weekly_cap') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Janela (inicio)</label>
                    <input type="time" wire:model="proactive_window_start"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    @error('proactive_window_start') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Janela (fim)</label>
                    <input type="time" wire:model="proactive_window_end"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    @error('proactive_window_end') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="mt-3 flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 flex items-center gap-1 text-xs font-medium">Palavra de opt-out
                        <x-info-tip text="Contato que mandar EXATAMENTE esta palavra (maiusculas/acentos ignorados) tem o opt-in revogado na hora, com registro na trilha de consentimento. Nao enviamos confirmacao automatica — crie uma regra reativa se quiser responder." />
                    </label>
                    <input type="text" maxlength="40" wire:model="proactive_optout_word"
                        class="w-40 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    @error('proactive_optout_word') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div class="w-full">
                    <label class="mb-1 flex items-center gap-1 text-xs font-medium">Rodape de saida (padrao da conta)
                        <x-info-tip text="P-4: toda mensagem proativa SEMPRE sai com esta instrucao no fim — quem sabe sair manda a palavra; quem nao sabe, denuncia. Tem que conter {palavra_sair} (vira a palavra de opt-out atual no envio, ate em campanha ja aprovada). Campanha nova comeca com este texto; da pra personalizar por campanha." />
                    </label>
                    <textarea wire:model.live.debounce.400ms="proactive_optout_footer" rows="2" maxlength="500"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"></textarea>
                    @error('proactive_optout_footer') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    <p class="mt-1 text-[11px] text-zinc-500">Como sai hoje: <span class="rounded bg-zinc-100 px-1.5 py-0.5 italic dark:bg-zinc-800">{{ $footerPreview }}</span></p>
                </div>
                <button type="button" wire:click="saveProactive"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-3 py-2 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
                    <flux:icon icon="check" variant="micro" /> Salvar proativas
                </button>
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

    {{-- MODAL: confirmar AFROUXAMENTO da IA (Fatia 4 — reduzir limiar / desmarcar tema) --}}
    @if ($confirmingAiRelax)
        <x-modal wireClose="cancelAiRelax" title="Deixar a IA mais autonoma?">
            <div class="flex items-start gap-3">
                <div class="mt-0.5 text-amber-500"><flux:icon icon="exclamation-triangle" class="size-6" /></div>
                <div class="space-y-2 text-sm text-zinc-600 dark:text-zinc-300">
                    <p>Essas mudancas <strong>liberam a IA a responder sozinha em mais casos</strong>:</p>
                    <ul class="list-disc space-y-1 pl-5">
                        @foreach ($aiRelaxWarnings as $aviso)
                            <li>{{ $aviso }}</li>
                        @endforeach
                    </ul>
                    <p class="text-xs text-zinc-400">Vale so pra decisoes futuras. Da pra voltar atras aqui a qualquer momento.</p>
                </div>
            </div>
            <div class="flex justify-end gap-2 pt-4">
                <button type="button" wire:click="cancelAiRelax" data-autofocus class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                <button type="button" wire:click="aiRelaxConfirmed" class="inline-flex items-center gap-1.5 rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700">
                    <flux:icon icon="check" variant="micro" /> Confirmar mudanca
                </button>
            </div>
        </x-modal>
    @endif

    {{-- MODAL: LIGAR proativas (P-1) --}}
    @if ($confirmingProactiveEnable)
        <x-modal wireClose="cancelProactiveEnable" title="Ligar mensagens proativas?">
            <div class="flex items-start gap-3">
                <div class="mt-0.5 text-rose-500"><flux:icon icon="megaphone" class="size-6" /></div>
                <p class="text-sm text-zinc-600 dark:text-zinc-300">
                    Proativa = o sistema <strong>INICIA</strong> conversa — e o maior risco de bloqueio do
                    WhatsApp em todo o roadmap. Este interruptor so ARMA os freios: nada dispara sem
                    campanha criada, revisada e <strong>aprovada por voce</strong> (P-2/P-3), so pra contatos
                    com opt-in explicito, dentro dos tetos. Confirmar?
                </p>
            </div>
            <div class="flex justify-end gap-2 pt-4">
                <button type="button" wire:click="cancelProactiveEnable" data-autofocus class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                <button type="button" wire:click="proactiveEnableConfirmed" class="inline-flex items-center gap-1.5 rounded-lg bg-rose-600 px-4 py-2 text-sm font-medium text-white hover:bg-rose-700">
                    <flux:icon icon="megaphone" variant="micro" /> Ligar proativas
                </button>
            </div>
        </x-modal>
    @endif

    {{-- MODAL: confirmar AFROUXAMENTO das proativas (P-1) --}}
    @if ($confirmingProactiveRelax)
        <x-modal wireClose="cancelProactiveRelax" title="Afrouxar os freios das proativas?">
            <div class="flex items-start gap-3">
                <div class="mt-0.5 text-amber-500"><flux:icon icon="exclamation-triangle" class="size-6" /></div>
                <div class="space-y-2 text-sm text-zinc-600 dark:text-zinc-300">
                    <p>Essas mudancas <strong>aumentam o risco de ban</strong>:</p>
                    <ul class="list-disc space-y-1 pl-5">
                        @foreach ($proactiveRelaxWarnings as $aviso)
                            <li>{{ $aviso }}</li>
                        @endforeach
                    </ul>
                    <p class="text-xs text-zinc-400">Da pra voltar atras aqui a qualquer momento.</p>
                </div>
            </div>
            <div class="flex justify-end gap-2 pt-4">
                <button type="button" wire:click="cancelProactiveRelax" data-autofocus class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                <button type="button" wire:click="proactiveRelaxConfirmed" class="inline-flex items-center gap-1.5 rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700">
                    <flux:icon icon="check" variant="micro" /> Confirmar mudanca
                </button>
            </div>
        </x-modal>
    @endif
</div>
