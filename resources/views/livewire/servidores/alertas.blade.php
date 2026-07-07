<div class="h-full overflow-y-auto">
    <div class="mx-auto max-w-4xl p-6 space-y-4">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <h1 class="text-xl font-semibold">Alertas</h1>
                <x-info-tip text="Limiar por metrica com dois niveis (warning/critical) e duracao de histerese por nivel (a condicao precisa persistir pela janela para abrir incidente). Padroes globais valem para todos os servidores; sobrescrita por servidor tem precedencia — inclusive desligada (silencia a metrica naquele servidor)." />
            </div>
            <select wire:model.live="servidorId" aria-label="Escopo das regras"
                class="rounded-lg border border-zinc-300 bg-white px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                <option value="">Padroes globais (todos os servidores)</option>
                @foreach ($servers as $s)
                    <option value="{{ $s->id }}">Sobrescritas: {{ $s->name }}</option>
                @endforeach
            </select>
        </div>

        <div class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/50 dark:text-amber-300">
            <flux:icon icon="bell-slash" variant="micro" class="inline size-3.5" />
            <strong>Modo silencioso (S2):</strong> as transicoes gravam incidente e aparecem nos <strong>Logs</strong>
            como "teria notificado" — nenhum WhatsApp e enviado. O canal liga na proxima fatia, depois da calibracao.
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white divide-y divide-zinc-100 dark:border-zinc-800 dark:bg-zinc-900 dark:divide-zinc-800">
            @foreach ($linhas as $linha)
                @php $r = $linha['rule']; @endphp
                <div class="flex items-center gap-3 p-3" wire:key="rule-{{ $linha['metric'] }}-{{ $r->id }}">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">{{ \App\Servers\AlertRule::LABELS[$linha['metric']] }}</span>
                            @if ($servidorId)
                                <span @class([
                                    'rounded px-1.5 text-[10px]',
                                    'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300' => $linha['override'],
                                    'bg-zinc-100 text-zinc-500 dark:bg-zinc-800' => ! $linha['override'],
                                ])>{{ $linha['override'] ? 'sobrescrita' : 'padrao global' }}</span>
                            @endif
                            @unless ($r->enabled)
                                <span class="inline-flex items-center rounded-full bg-zinc-200 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">Desligada</span>
                            @endunless
                        </div>
                        <div class="mt-0.5 text-xs text-zinc-400">
                            @if ($linha['metric'] === 'watchdog')
                                warning &ge; {{ (int) $r->warning_threshold }}s sem reportar &middot; critical &ge; {{ (int) $r->critical_threshold }}s
                            @else
                                @php $unidade = $linha['metric'] === 'load' ? '/nucleo' : '%'; @endphp
                                warning &ge; {{ $r->warning_threshold !== null ? $r->warning_threshold.$unidade : '—' }} por {{ (int) ($r->warning_for_s / 60) }}min
                                &middot; critical &ge; {{ $r->critical_threshold }}{{ $unidade }} por {{ (int) ($r->critical_for_s / 60) }}min
                            @endif
                        </div>
                    </div>

                    @if ($servidorId && ! $linha['override'])
                        <button type="button" wire:click="override('{{ $linha['metric'] }}')"
                            class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-zinc-300 px-2.5 py-1.5 text-xs font-medium text-zinc-600 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                            <flux:icon icon="pencil-square" variant="micro" /> Sobrescrever
                        </button>
                    @else
                        <flux:dropdown position="bottom" align="end">
                            <button type="button" class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" aria-label="Acoes">
                                <flux:icon icon="ellipsis-vertical" variant="micro" />
                            </button>
                            <flux:menu>
                                <flux:menu.item wire:click="edit({{ $r->id }})" icon="pencil-square">Editar</flux:menu.item>
                                <flux:menu.item wire:click="toggleEnabled({{ $r->id }})" icon="{{ $r->enabled ? 'pause' : 'play' }}">
                                    {{ $r->enabled ? 'Desligar' : 'Ligar' }}
                                </flux:menu.item>
                                @if ($linha['override'])
                                    <flux:menu.separator />
                                    <flux:menu.item wire:click="askRemoveOverride({{ $r->id }})" icon="trash" variant="danger">Remover sobrescrita</flux:menu.item>
                                @endif
                            </flux:menu>
                        </flux:dropdown>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- ================= PARTICOES (por servidor) ================= --}}
        @if ($servidorId)
            <div class="flex items-center gap-2 pt-4">
                <h2 class="text-lg font-semibold">Particoes reportadas</h2>
                <x-info-tip text="As particoes que ESTE servidor reportou (descobertas da ultima amostra). O coletor manda todas as particoes reais; aqui voce escolhe QUAIS alertar e o limiar de cada uma — sem reinstalar nada no servidor. Sem sobrescrita, cada particao segue o padrao de disco." />
            </div>

            @if ($particoes === [])
                <div class="rounded-lg bg-zinc-100 px-3 py-2 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                    Este servidor ainda nao reportou particoes (aguardando o coletor enviar a primeira amostra).
                </div>
            @else
                <div class="rounded-xl border border-zinc-200 bg-white divide-y divide-zinc-100 dark:border-zinc-800 dark:bg-zinc-900 dark:divide-zinc-800">
                    @foreach ($particoes as $p)
                        @php $pr = $p['rule']; $ligada = $pr && $pr->enabled; @endphp
                        <div class="flex items-center gap-3 p-3" wire:key="part-{{ $p['mount'] }}">
                            <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-zinc-500 dark:bg-zinc-800">
                                <flux:icon icon="circle-stack" variant="micro" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <code class="font-medium">{{ $p['mount'] }}</code>
                                    @if ($p['pct'] !== null)
                                        <span class="text-xs text-zinc-400">uso {{ $p['pct'] }}%</span>
                                    @endif
                                    @if ($p['override'])
                                        <span class="rounded bg-sky-100 px-1.5 text-[10px] text-sky-700 dark:bg-sky-950 dark:text-sky-300">sobrescrita</span>
                                    @endif
                                    @unless ($ligada)
                                        <span class="inline-flex items-center rounded-full bg-zinc-200 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">Silenciada</span>
                                    @endunless
                                </div>
                                <div class="mt-0.5 text-xs text-zinc-400">
                                    @if ($pr)
                                        warning &ge; {{ $pr->warning_threshold !== null ? $pr->warning_threshold.'%' : '—' }}
                                        &middot; critical &ge; {{ $pr->critical_threshold }}%
                                        @if ($p['override']) (desta particao) @else (padrao) @endif
                                    @endif
                                </div>
                            </div>
                            <button type="button" wire:click="togglePartition('{{ $p['mount'] }}')"
                                class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-zinc-300 px-2.5 py-1.5 text-xs font-medium text-zinc-600 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                                <flux:icon icon="{{ $ligada ? 'bell-slash' : 'bell' }}" variant="micro" /> {{ $ligada ? 'Silenciar' : 'Alertar' }}
                            </button>
                            <button type="button" wire:click="overridePartition('{{ $p['mount'] }}')"
                                class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-zinc-300 px-2.5 py-1.5 text-xs font-medium text-zinc-600 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                                <flux:icon icon="pencil-square" variant="micro" /> Limiar
                            </button>
                            @if ($p['override'])
                                <button type="button" wire:click="askRemoveOverride({{ $pr->id }})"
                                    class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-zinc-300 px-2.5 py-1.5 text-xs font-medium text-zinc-500 hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800" title="Voltar ao padrao">
                                    <flux:icon icon="arrow-uturn-left" variant="micro" />
                                </button>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        @endif

        {{-- ================= DESTINATARIOS (roteamento) ================= --}}
        <div class="flex items-center justify-between gap-3 pt-4">
            <div class="flex items-center gap-2">
                <h2 class="text-lg font-semibold">Destinatarios</h2>
                <x-info-tip text="Quem recebe os alertas no WhatsApp. Filtro por severidade (warning recebe warning+critical; critical so critical) e por alvo (um servidor, um grupo, ou todos). E-mail opcional serve de fallback quando o WhatsApp falha." />
            </div>
            <button type="button" wire:click="novoContato"
                class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-3 py-2 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
                <flux:icon icon="plus" variant="micro" /> Novo destinatario
            </button>
        </div>

        @unless ($notificacoesLigadas)
            <div class="rounded-lg bg-zinc-100 px-3 py-2 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                <flux:icon icon="bell-slash" variant="micro" class="inline size-3.5" />
                Notificacoes <strong>desligadas</strong> (modo silencioso). Cadastre os destinatarios agora; quando o
                canal for ligado (<code>SERVERS_NOTIFICATIONS_ENABLED=true</code>), os alertas passam a sair para eles.
            </div>
        @endunless

        <div class="rounded-xl border border-zinc-200 bg-white divide-y divide-zinc-100 dark:border-zinc-800 dark:bg-zinc-900 dark:divide-zinc-800">
            @forelse ($contatos as $c)
                <div class="flex items-center gap-3 p-3" wire:key="contact-{{ $c->id }}">
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-zinc-500 dark:bg-zinc-800">
                        <flux:icon icon="user" variant="micro" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="truncate font-medium">{{ $c->name }}</span>
                            <span @class([
                                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' => $c->min_level === 'critical',
                                'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300' => $c->min_level === 'warning',
                            ])>{{ $c->min_level === 'critical' ? 'so critical' : 'warning+' }}</span>
                            @unless ($c->enabled)
                                <span class="inline-flex items-center rounded-full bg-zinc-200 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">Desligado</span>
                            @endunless
                        </div>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-zinc-400">
                            <span class="font-mono">{{ $c->phone }}</span>
                            @if ($c->email)
                                <span aria-hidden="true">&middot;</span><span>{{ $c->email }}</span>
                            @endif
                            <span aria-hidden="true">&middot;</span>
                            <span>
                                @if ($c->server_id)
                                    {{ optional($servers->firstWhere('id', $c->server_id))->name ?? 'servidor' }}
                                @elseif ($c->grupo)
                                    grupo {{ $c->grupo }}
                                @else
                                    todos os servidores
                                @endif
                            </span>
                        </div>
                    </div>
                    <flux:dropdown position="bottom" align="end">
                        <button type="button" class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" aria-label="Acoes">
                            <flux:icon icon="ellipsis-vertical" variant="micro" />
                        </button>
                        <flux:menu>
                            <flux:menu.item wire:click="editContato({{ $c->id }})" icon="pencil-square">Editar</flux:menu.item>
                            <flux:menu.item wire:click="toggleContato({{ $c->id }})" icon="{{ $c->enabled ? 'pause' : 'play' }}">
                                {{ $c->enabled ? 'Desligar' : 'Ligar' }}
                            </flux:menu.item>
                            <flux:menu.separator />
                            <flux:menu.item wire:click="askDeleteContato({{ $c->id }})" icon="trash" variant="danger">Remover</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>
            @empty
                <div class="flex flex-col items-center gap-2 p-8 text-center text-zinc-400">
                    <flux:icon icon="user-plus" class="size-7" />
                    <p class="text-sm">Nenhum destinatario. Sem destinatario, um alerta ligado nao teria para quem ir.</p>
                </div>
            @endforelse
        </div>
    </div>

    {{-- MODAL: destinatario --}}
    @if ($showContactForm)
        <x-modal wireClose="closeContato" title="{{ $contactEditingId ? 'Editar destinatario' : 'Novo destinatario' }}">
            <form id="contact-form" wire:submit="saveContato" class="space-y-3">
                <div>
                    <label class="mb-1 block text-xs font-medium">Nome</label>
                    <input type="text" wire:model="c_name" data-autofocus placeholder="ex.: Fabio (celular)"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    @error('c_name') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium">WhatsApp (numero)</label>
                        <input type="text" wire:model="c_phone" placeholder="5511999999999"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                        @error('c_phone') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium">E-mail (fallback, opcional)</label>
                        <input type="email" wire:model="c_email" placeholder="fabio@empresa.com"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                        @error('c_email') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium">Severidade minima</label>
                        <select wire:model="c_min_level" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                            <option value="warning">Warning e acima (tudo)</option>
                            <option value="critical">So critical</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium">Alvo</label>
                        <select wire:model="c_server_id" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                            <option value="">Todos / por grupo</option>
                            @foreach ($servers as $s)
                                <option value="{{ $s->id }}">{{ $s->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div @if ($c_server_id) hidden @endif>
                    <label class="mb-1 block text-xs font-medium">Grupo (opcional; vazio = todos os servidores)</label>
                    <input type="text" wire:model="c_grupo" placeholder="ex.: producao"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    @error('c_grupo') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    <p class="mt-1 text-[11px] text-zinc-400">Um servidor especifico tem precedencia; sem alvo, recebe de todos.</p>
                </div>
            </form>
            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="closeContato" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                    <button type="submit" form="contact-form" class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-zinc-900">
                        <flux:icon icon="check" variant="micro" /> Salvar
                    </button>
                </div>
            </x-slot:footer>
        </x-modal>
    @endif

    {{-- MODAL: remover destinatario --}}
    @if ($contactDeleting)
        <x-modal wireClose="cancelDeleteContato" title="Remover destinatario">
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                Remover <strong>"{{ $contactDeleting->name }}"</strong> dos alertas? Ele deixa de receber notificacoes.
            </p>
            <div class="flex justify-end gap-2 pt-4">
                <button type="button" wire:click="cancelDeleteContato" data-autofocus class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                <button type="button" wire:click="deleteContatoConfirmed" class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                    <flux:icon icon="trash" variant="micro" /> Remover
                </button>
            </div>
        </x-modal>
    @endif

    {{-- MODAL: editar regra --}}
    @if ($editingId)
        <x-modal wireClose="closeEdit" title="Editar regra — {{ $editingLabel }}">
            <form id="rule-form" wire:submit="save" class="space-y-3">
                @php $ehWatchdog = $editingMetric === 'watchdog'; @endphp
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium">Warning ({{ $ehWatchdog ? 'segundos sem reportar' : ($editingMetric === 'load' ? 'load/nucleo' : '%') }})</label>
                        <input type="number" step="0.1" wire:model="warning_threshold" data-autofocus
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                        @error('warning_threshold') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium">Critical ({{ $ehWatchdog ? 'segundos sem reportar' : ($editingMetric === 'load' ? 'load/nucleo' : '%') }})</label>
                        <input type="number" step="0.1" wire:model="critical_threshold"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                        @error('critical_threshold') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3" @if ($ehWatchdog) hidden @endif>
                    <div>
                        <label class="mb-1 block text-xs font-medium">Persistir por (s) — warning</label>
                        <input type="number" wire:model="warning_for_s"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                        @error('warning_for_s') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium">Persistir por (s) — critical</label>
                        <input type="number" wire:model="critical_for_s"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                        @error('critical_for_s') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3" @if ($ehWatchdog) hidden @endif>
                    <div>
                        <label class="mb-1 block text-xs font-medium">Resolver apos (s) abaixo do limiar</label>
                        <input type="number" wire:model="resolve_for_s" placeholder="vazio = igual a subida (warning)"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                        @error('resolve_for_s') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                        <p class="mt-1 text-[11px] text-zinc-400">Debounce anti-flapping: a metrica precisa ficar boa por este tempo antes de fechar.</p>
                    </div>
                    <div></div>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="enabled" class="rounded border-zinc-300 dark:border-zinc-700">
                    Regra ligada
                </label>
                @if ($ehWatchdog)
                    <p class="text-[11px] text-zinc-400">Watchdog nao usa histerese: o gap (agora − ultimo reporte) ja e uma duracao.</p>
                @endif

                {{-- CADENCIA DE RE-AVISO por nivel --}}
                <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <p class="mb-2 text-xs font-semibold">Cadencia de re-aviso (enquanto o incidente segue aberto)</p>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" wire:model.live="warning_repeat_on" class="rounded border-zinc-300 dark:border-zinc-700">
                                Warning: re-avisar
                            </label>
                            <div class="mt-1 flex items-center gap-1 text-sm" @unless ($warning_repeat_on) hidden @endunless>
                                a cada <input type="number" wire:model="warning_repeat_min" class="w-20 rounded-lg border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-700 dark:bg-zinc-800"> min
                            </div>
                            <p class="text-[11px] text-zinc-400" @if ($warning_repeat_on) hidden @endif>avisar 1 vez</p>
                            @error('warning_repeat_min') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" wire:model.live="critical_repeat_on" class="rounded border-zinc-300 dark:border-zinc-700">
                                Critical: re-avisar
                            </label>
                            <div class="mt-1 flex items-center gap-1 text-sm" @unless ($critical_repeat_on) hidden @endunless>
                                a cada <input type="number" wire:model="critical_repeat_min" class="w-20 rounded-lg border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-700 dark:bg-zinc-800"> min
                            </div>
                            <p class="text-[11px] text-zinc-400" @if ($critical_repeat_on) hidden @endif>avisar 1 vez</p>
                            @error('critical_repeat_min') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                {{-- MENSAGENS configuraveis (padrao editavel + rotacao) + variaveis --}}
                <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <p class="mb-1 text-xs font-semibold">Mensagens do alerta</p>
                    <p class="mb-2 text-[11px] text-zinc-400">
                        A <strong>1a mensagem</strong> de cada nivel e a <strong>padrao</strong> (vai no disparo) e ja vem
                        preenchida — <strong>edite a vontade</strong>. Adicione outras para <strong>rotacao</strong>: a cada
                        re-aviso o sistema usa a proxima (repete a ultima ao acabar).
                    </p>
                    <p class="mb-2 rounded bg-zinc-100 px-2 py-1 text-[11px] text-zinc-500 dark:bg-zinc-800 dark:text-zinc-300">
                        Variaveis:
                        @foreach (\App\Servers\AlertMessageResolver::VARIAVEIS as $v => $desc)
                            <code>{{ $v }}</code><span class="text-zinc-400">={{ $desc }}</span>{{ ! $loop->last ? ' · ' : '' }}
                        @endforeach
                    </p>

                    @foreach (['warning' => 'Warning', 'critical' => 'Critical'] as $lvl => $rot)
                        @php $lista = $lvl === 'warning' ? $msgsWarning : $msgsCritical; @endphp
                        <div class="mt-2">
                            <p class="text-[11px] font-medium text-zinc-500">{{ $rot }}</p>
                            @foreach ($lista as $i => $txt)
                                <div class="mt-1 flex items-start gap-1" wire:key="msg-{{ $lvl }}-{{ $i }}">
                                    <span class="w-16 shrink-0 pt-2 text-[11px] text-zinc-400">{{ $i === 0 ? 'padrao' : ($i + 1).'ª' }}</span>
                                    <input type="text" wire:model="{{ $lvl === 'warning' ? 'msgsWarning' : 'msgsCritical' }}.{{ $i }}"
                                        placeholder="ex.: 🔴 {servidor} ({ip}, grupo {grupo}): {metrica} em {valor}"
                                        class="w-full rounded-lg border border-zinc-300 bg-white px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                                    @if ($i > 0)
                                        <button type="button" wire:click="removeMsg('{{ $lvl }}', {{ $i }})" class="rounded-lg p-1.5 text-zinc-400 hover:bg-zinc-100 hover:text-red-600 dark:hover:bg-zinc-800" title="Remover">
                                            <flux:icon icon="x-mark" variant="micro" />
                                        </button>
                                    @endif
                                </div>
                            @endforeach
                            <button type="button" wire:click="addMsg('{{ $lvl }}')" class="mt-1 inline-flex items-center gap-1 text-xs text-emerald-700 hover:underline dark:text-emerald-400">
                                <flux:icon icon="plus" variant="micro" /> Adicionar mensagem (rotacao)
                            </button>
                        </div>
                    @endforeach

                    <div class="mt-3">
                        <label class="mb-1 block text-[11px] font-medium text-zinc-500">Mensagem de resolucao (1 vez)</label>
                        <input type="text" wire:model="msgResolved" placeholder="ex.: ✅ {servidor} ({ip}): {metrica} normalizou"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    </div>
                </div>
            </form>
            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="closeEdit" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                    <button type="submit" form="rule-form" class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-zinc-900">
                        <flux:icon icon="check" variant="micro" /> Salvar
                    </button>
                </div>
            </x-slot:footer>
        </x-modal>
    @endif

    {{-- MODAL: remover sobrescrita --}}
    @if ($removing)
        <x-modal wireClose="cancelRemoveOverride" title="Remover sobrescrita">
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                Remover a sobrescrita de <strong>{{ \App\Servers\AlertRule::LABELS[$removing->metric] ?? $removing->metric }}</strong>
                deste servidor? Ele volta a seguir o <strong>padrao global</strong>.
            </p>
            <div class="flex justify-end gap-2 pt-4">
                <button type="button" wire:click="cancelRemoveOverride" data-autofocus class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                <button type="button" wire:click="removeOverrideConfirmed" class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                    <flux:icon icon="trash" variant="micro" /> Remover
                </button>
            </div>
        </x-modal>
    @endif
</div>
