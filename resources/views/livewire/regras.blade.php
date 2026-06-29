<div class="h-full overflow-y-auto">
    <div class="mx-auto max-w-4xl p-6 space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">Regras (automacoes)</h1>
            <div class="flex items-center gap-2">
                <button type="button" wire:click="openTester"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 px-3 py-2 text-sm font-medium hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">
                    <flux:icon icon="beaker" variant="micro" /> Testar
                </button>
                <button type="button" wire:click="novo"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-3 py-2 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
                    <flux:icon icon="plus" variant="micro" /> Nova regra
                </button>
            </div>
        </div>

        <p class="text-sm text-zinc-500">
            Varios gatilhos levam a mesma regra; varias respostas variam (escolha aleatoria no envio,
            ajuda anti-ban). <strong>contains</strong> casa palavra inteira; acento/maiusculas ignorados.
            Primeira regra (de cima) que casa vence.
        </p>

        <div class="rounded-xl border border-zinc-200 bg-white divide-y divide-zinc-100 dark:border-zinc-800 dark:bg-zinc-900 dark:divide-zinc-800">
            @forelse ($rules as $rule)
                @php $trigs = $rule->triggerList(); $resps = $rule->responseList(); @endphp
                <div class="flex items-start gap-3 p-3" wire:key="r-{{ $rule->id }}">
                    <div class="flex flex-col pt-1 text-zinc-400">
                        <button type="button" wire:click="move({{ $rule->id }}, 'up')" class="hover:text-zinc-700 dark:hover:text-zinc-200" aria-label="Subir">
                            <flux:icon icon="chevron-up" variant="micro" />
                        </button>
                        <button type="button" wire:click="move({{ $rule->id }}, 'down')" class="hover:text-zinc-700 dark:hover:text-zinc-200" aria-label="Descer">
                            <flux:icon icon="chevron-down" variant="micro" />
                        </button>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-1.5">
                            @foreach ($trigs as $t)
                                <span class="inline-flex items-center gap-1 rounded-md bg-zinc-100 px-1.5 py-0.5 text-xs dark:bg-zinc-800">
                                    <span class="font-mono text-[10px] text-zinc-400">{{ $t['type'] }}</span>
                                    <span class="font-medium">{{ $t['value'] }}</span>
                                </span>
                            @endforeach
                        </div>
                        <div class="mt-1 flex items-start gap-1 text-sm text-zinc-500">
                            <span class="shrink-0">&rarr;</span>
                            <span class="min-w-0 truncate">{{ $resps->first() }}</span>
                            @if ($resps->count() > 1)
                                <span class="shrink-0 rounded-full bg-emerald-100 px-1.5 text-[10px] font-medium text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">+{{ $resps->count() - 1 }} resp.</span>
                            @endif
                        </div>
                        {{-- Meta: frequencia (S2) + escopo (S3) --}}
                        @php
                            $freqLabel = match ($rule->cooldown_mode) {
                                'sempre' => 'sempre', '1x_dia' => '1x/dia',
                                'cada_n' => 'a cada ' . (int) $rule->cooldown_minutes . 'min',
                                default => 'rate global',
                            };
                        @endphp
                        <div class="mt-1 flex flex-wrap items-center gap-1.5 text-[10px] text-zinc-400">
                            <span class="inline-flex items-center gap-1 rounded bg-zinc-100 px-1.5 py-0.5 dark:bg-zinc-800"><flux:icon icon="clock" variant="micro" class="size-3" /> {{ $freqLabel }}</span>
                            @if ($rule->scope === 'contatos')
                                <span class="inline-flex items-center gap-1 rounded bg-sky-100 px-1.5 py-0.5 text-sky-700 dark:bg-sky-950 dark:text-sky-300"><flux:icon icon="user" variant="micro" class="size-3" /> {{ $rule->contacts->count() }} contato(s)</span>
                            @else
                                <span class="inline-flex items-center gap-1 rounded bg-zinc-100 px-1.5 py-0.5 dark:bg-zinc-800"><flux:icon icon="globe-alt" variant="micro" class="size-3" /> todos</span>
                            @endif
                        </div>
                    </div>

                    <span @class([
                        'mt-0.5 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
                        'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' => $rule->enabled,
                        'bg-zinc-200 text-zinc-500 dark:bg-zinc-800' => ! $rule->enabled,
                    ])>{{ $rule->enabled ? 'ativa' : 'inativa' }}</span>

                    <flux:dropdown position="bottom" align="end">
                        <button type="button" class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" aria-label="Acoes">
                            <flux:icon icon="ellipsis-vertical" variant="micro" />
                        </button>
                        <flux:menu>
                            <flux:menu.item wire:click="edit({{ $rule->id }})" icon="pencil-square">Editar</flux:menu.item>
                            <flux:menu.item wire:click="toggle({{ $rule->id }})" icon="{{ $rule->enabled ? 'pause' : 'play' }}">
                                {{ $rule->enabled ? 'Desativar' : 'Ativar' }}
                            </flux:menu.item>
                            <flux:menu.separator />
                            <flux:menu.item wire:click="confirmDelete({{ $rule->id }})" icon="trash" variant="danger">Excluir</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>
            @empty
                <div class="flex flex-col items-center gap-2 p-10 text-center text-zinc-400">
                    <flux:icon icon="bolt" class="size-8" />
                    <p class="text-sm">Nenhuma regra criada. Crie a primeira automacao.</p>
                </div>
            @endforelse
        </div>
    </div>

    {{-- MODAL: criar/editar regra (rico) --}}
    @if ($showForm)
        <x-modal wireClose="closeForm" title="{{ $editingId ? 'Editar regra' : 'Nova regra' }}" maxWidth="2xl">
            <form id="rule-form" wire:submit="save" class="space-y-4">
                {{-- GATILHOS --}}
                <div>
                    <div class="mb-1 flex items-center justify-between">
                        <label class="text-xs font-semibold uppercase tracking-wide text-zinc-400">Gatilhos</label>
                        <button type="button" wire:click="addTrigger" class="inline-flex items-center gap-1 text-xs text-emerald-600 hover:underline">
                            <flux:icon icon="plus" variant="micro" /> gatilho
                        </button>
                    </div>
                    <p class="mb-2 text-[11px] text-zinc-400">Qualquer gatilho que casar dispara a regra.</p>
                    <div class="space-y-2">
                        @foreach ($triggers as $i => $t)
                            {{-- S4: [tipo] [precisao] [texto] [x] na MESMA linha. --}}
                            <div wire:key="trg-{{ $i }}" class="flex flex-wrap items-start gap-2 sm:flex-nowrap">
                                <select wire:model.live="triggers.{{ $i }}.type" class="w-28 shrink-0 rounded-lg border border-zinc-300 bg-white px-2 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                                    <option value="contains">contains</option>
                                    <option value="exact">exact</option>
                                    <option value="starts_with">starts_with</option>
                                    <option value="regex">regex</option>
                                </select>

                                @if ($t['type'] !== 'regex')
                                    <select wire:model.live="triggers.{{ $i }}.precision" title="Precisao do match" class="w-32 shrink-0 rounded-lg border border-zinc-300 bg-white px-2 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                                        <option value="exato">exato</option>
                                        <option value="tolerante">tolerante</option>
                                    </select>
                                @endif

                                <div class="min-w-0 flex-1">
                                    <input type="text" wire:model="triggers.{{ $i }}.value" placeholder="{{ $t['type'] === 'regex' ? 'ex.: ^pre[cç]o' : 'ex.: horario' }}"
                                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                                    @error("triggers.{$i}.value") <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                                    @if ($t['type'] === 'regex')
                                        <p class="mt-1 text-[11px] text-amber-600 dark:text-amber-400">
                                            <flux:icon icon="exclamation-triangle" variant="micro" class="inline size-3" />
                                            Regex avancado: validado e protegido, mas teste antes. Sem delimitadores; flags i+u aplicadas.
                                        </p>
                                    @elseif (($t['precision'] ?? 'exato') === 'tolerante')
                                        <div class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                                            <select wire:model="triggers.{{ $i }}.fuzzy_level" class="rounded-lg border border-zinc-300 bg-white px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800">
                                                <option value="baixa">baixa</option>
                                                <option value="media">media</option>
                                                <option value="alta">alta</option>
                                            </select>
                                            <span class="text-amber-600 dark:text-amber-400">tolera erro de digitacao; palavra curta (&lt;4) segue exata. Teste no "Testar".</span>
                                        </div>
                                    @endif
                                </div>

                                <button type="button" wire:click="removeTrigger({{ $i }})" @disabled(count($triggers) <= 1)
                                    class="mt-2.5 text-zinc-400 hover:text-red-500 disabled:opacity-30" aria-label="Remover gatilho">
                                    <flux:icon icon="x-mark" variant="micro" />
                                </button>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- RESPOSTAS --}}
                <div>
                    <div class="mb-1 flex items-center justify-between">
                        <label class="text-xs font-semibold uppercase tracking-wide text-zinc-400">Respostas</label>
                        <button type="button" wire:click="addResponse" class="inline-flex items-center gap-1 text-xs text-emerald-600 hover:underline">
                            <flux:icon icon="plus" variant="micro" /> resposta
                        </button>
                    </div>
                    <p class="mb-2 text-[11px] text-zinc-400">Com mais de uma, o robo sorteia qual enviar (varia a resposta, ajuda anti-ban).</p>
                    <div class="space-y-2">
                        @foreach ($responses as $i => $r)
                            <div wire:key="resp-{{ $i }}" class="flex items-start gap-2">
                                <div class="min-w-0 flex-1">
                                    <textarea wire:model="responses.{{ $i }}" rows="2" placeholder="ex.: {saudacao}, {nome}! Atendo das 8h as 18h."
                                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"></textarea>
                                    @error("responses.{$i}") <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                                </div>
                                <button type="button" wire:click="removeResponse({{ $i }})" @disabled(count($responses) <= 1)
                                    class="mt-1.5 text-zinc-400 hover:text-red-500 disabled:opacity-30" aria-label="Remover resposta">
                                    <flux:icon icon="x-mark" variant="micro" />
                                </button>
                            </div>
                        @endforeach
                    </div>
                    @error('responses') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>

                {{-- PLACEHOLDERS --}}
                <div class="rounded-lg bg-zinc-50 p-3 text-[11px] text-zinc-500 dark:bg-zinc-800/50">
                    <span class="font-medium text-zinc-600 dark:text-zinc-300">Placeholders (processados no envio):</span>
                    <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">{nome}</code> nome do contato ·
                    <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">{saudacao}</code> bom dia/tarde/noite ·
                    <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">{data}</code> ·
                    <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">{hora}</code>
                </div>

                {{-- FREQUENCIA (S2) --}}
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-zinc-400">Frequencia (por contato)</label>
                    <div class="flex flex-wrap items-center gap-2">
                        <select wire:model.live="cooldownMode" class="rounded-lg border border-zinc-300 bg-white px-2 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                            <option value="global">Padrao (rate global por contato)</option>
                            <option value="sempre">Sempre que casar</option>
                            <option value="1x_dia">1x por dia</option>
                            <option value="cada_n">A cada N minutos</option>
                        </select>
                        @if ($cooldownMode === 'cada_n')
                            <div class="flex items-center gap-1">
                                <input type="number" min="1" wire:model="cooldownMinutes" class="w-24 rounded-lg border border-zinc-300 bg-white px-2 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                                <span class="text-xs text-zinc-500">min</span>
                            </div>
                            @error('cooldownMinutes') <p class="w-full text-xs text-red-500">{{ $message }}</p> @enderror
                        @endif
                    </div>
                    <p class="mt-1 text-[11px] text-zinc-400">Substitui o rate global so para esta regra. Os tetos de volume (intervalo/min/dia) continuam valendo.</p>
                </div>

                {{-- ESCOPO (S2 textos + S3 checkboxes) --}}
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-zinc-400">Escopo</label>
                    <div class="flex items-center gap-4 text-sm">
                        <label class="inline-flex items-center gap-1.5"><input type="radio" wire:model.live="scope" value="global"> Todos os Aprovados</label>
                        <label class="inline-flex items-center gap-1.5"><input type="radio" wire:model.live="scope" value="contatos"> Contatos Especificos</label>
                    </div>
                    @if ($scope === 'contatos')
                        <div class="mt-2 rounded-lg border border-zinc-200 dark:border-zinc-700">
                            <div class="flex items-center gap-2 border-b border-zinc-100 p-2 dark:border-zinc-800">
                                <div class="relative flex-1">
                                    <span class="pointer-events-none absolute inset-y-0 left-2 flex items-center text-zinc-400">
                                        <flux:icon icon="magnifying-glass" variant="micro" />
                                    </span>
                                    <input type="search" wire:model.live.debounce.250ms="scopeSearch" placeholder="Buscar nome ou numero..."
                                        class="w-full rounded-lg border border-zinc-300 bg-white py-1.5 pl-8 pr-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                                </div>
                                <span class="shrink-0 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                                    {{ count($scopeContactIds) }} selecionado(s)
                                </span>
                            </div>
                            <div class="max-h-48 overflow-y-auto p-1">
                                @forelse ($scopeContacts as $c)
                                    <label wire:key="sc-{{ $c->id }}" class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                        <input type="checkbox" value="{{ $c->id }}" wire:model.live="scopeContactIds" class="rounded border-zinc-300 dark:border-zinc-700">
                                        <span class="min-w-0 flex-1 truncate">{{ $c->push_name ?: \Illuminate\Support\Str::before($c->remote_jid, '@') }}</span>
                                        <span class="shrink-0 text-xs text-zinc-400">{{ \Illuminate\Support\Str::before($c->remote_jid, '@') }}</span>
                                    </label>
                                @empty
                                    <p class="px-2 py-3 text-center text-xs text-zinc-400">{{ $scopeSearch !== '' ? 'Nenhum contato encontrado.' : 'Nenhum contato na agenda ainda.' }}</p>
                                @endforelse
                            </div>
                        </div>
                        <p class="mt-1 text-[11px] text-zinc-400">Marque os contatos. A regra so dispara para esses contatos.</p>
                        @error('scopeContactIds') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                    @endif
                </div>

                <label class="inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="enabled" class="rounded border-zinc-300 dark:border-zinc-700"> Habilitada
                </label>
            </form>

            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="closeForm" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                    <button type="submit" form="rule-form" class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-zinc-900">
                        <flux:icon icon="check" variant="micro" wire:loading.remove wire:target="save" />
                        <flux:icon icon="arrow-path" variant="micro" class="animate-spin" wire:loading wire:target="save" />
                        Salvar
                    </button>
                </div>
            </x-slot:footer>
        </x-modal>
    @endif

    {{-- MODAL: testador (dry-run, S4) --}}
    @if ($showTester)
        <x-modal wireClose="closeTester" title="Testar regras (nao envia)" maxWidth="lg">
            <div class="space-y-3">
                <p class="text-xs text-zinc-500">
                    Simula uma mensagem recebida e mostra qual regra casaria, a resposta resolvida e se
                    algum freio bloquearia. <strong>Nao envia nada</strong> nem mexe nos contadores.
                </p>
                <div>
                    <label class="mb-1 block text-xs font-medium">Mensagem de exemplo</label>
                    <textarea wire:model="testSample" rows="2" placeholder="ex.: qual a senha do wifi?"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"></textarea>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Contato (opcional)</label>
                    <select wire:model="testContactId" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                        <option value="">Sem contato (so o match/resposta)</option>
                        @foreach ($contacts as $c)
                            <option value="{{ $c->id }}">{{ $c->push_name ?: \Illuminate\Support\Str::before($c->remote_jid, '@') }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-[11px] text-zinc-400">Com contato, avalia tambem os freios (escopo, aprovacao, cooldown, janela).</p>
                </div>

                @if ($testResult)
                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-3 text-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                        @if (! ($testResult['ok'] ?? false))
                            <p class="text-amber-600 dark:text-amber-400">{{ $testResult['erro'] ?? 'Erro.' }}</p>
                        @elseif (! $testResult['matched'])
                            <p class="flex items-center gap-1.5 text-zinc-500"><flux:icon icon="x-circle" variant="micro" /> Nenhuma regra casaria.</p>
                        @else
                            <div class="space-y-1.5">
                                <p class="flex items-center gap-1.5 font-medium text-emerald-700 dark:text-emerald-300">
                                    <flux:icon icon="check-circle" variant="micro" /> Casaria a regra #{{ $testResult['rule_id'] }}
                                </p>
                                <p class="text-xs text-zinc-500">Gatilho: <span class="font-mono">{{ $testResult['trigger'] }}</span>
                                    @if (($testResult['trigger_precision'] ?? 'exato') !== 'exato')
                                        <span class="rounded bg-amber-100 px-1 text-amber-700 dark:bg-amber-950 dark:text-amber-300">tolerante</span>
                                    @endif
                                </p>
                                <div class="rounded bg-white p-2 text-sm dark:bg-zinc-900">
                                    <span class="text-[11px] uppercase text-zinc-400">Resposta</span>
                                    <div class="whitespace-pre-wrap">{{ $testResult['resposta'] }}</div>
                                    @if (($testResult['respostas_total'] ?? 1) > 1)
                                        <p class="mt-1 text-[11px] text-zinc-400">(sorteia entre {{ $testResult['respostas_total'] }} respostas — mostrando a 1a)</p>
                                    @endif
                                </div>
                                @if ($testResult['bloqueio'] ?? null)
                                    <p class="flex items-center gap-1.5 text-red-600 dark:text-red-400">
                                        <flux:icon icon="no-symbol" variant="micro" /> Mas um freio bloquearia: {{ $testResult['bloqueio_label'] }}
                                    </p>
                                @elseif ($testResult['contato'] ?? null)
                                    <p class="flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400">
                                        <flux:icon icon="paper-airplane" variant="micro" /> Responderia (nenhum freio bloqueia agora).
                                    </p>
                                @else
                                    <p class="text-[11px] text-zinc-400">Escolha um contato para avaliar os freios.</p>
                                @endif

                                {{-- S3: quadro completo dos freios (passa/bloqueia/desligado) --}}
                                @if (! empty($testResult['freios']))
                                    <div class="mt-2 rounded-lg border border-zinc-200 dark:border-zinc-700">
                                        <div class="border-b border-zinc-100 px-2 py-1 text-[11px] font-semibold uppercase tracking-wide text-zinc-400 dark:border-zinc-800">Todos os freios</div>
                                        <ul class="divide-y divide-zinc-100 text-xs dark:divide-zinc-800">
                                            @foreach ($testResult['freios'] as $f)
                                                @php
                                                    [$cor, $icone, $txt] = match ($f['status']) {
                                                        'passa' => ['text-emerald-600 dark:text-emerald-400', 'check-circle', 'passa'],
                                                        'bloqueia' => ['text-red-600 dark:text-red-400', 'no-symbol', 'bloqueia'],
                                                        'desligado' => ['text-zinc-400', 'minus-circle', 'desligado'],
                                                        default => ['text-zinc-400', 'minus-circle', 'n/a'],
                                                    };
                                                @endphp
                                                <li class="flex items-center justify-between gap-2 px-2 py-1.5">
                                                    <span class="min-w-0 truncate text-zinc-600 dark:text-zinc-300">
                                                        {{ $f['label'] }}
                                                        @if ($f['detalhe'])<span class="text-zinc-400">· {{ $f['detalhe'] }}</span>@endif
                                                    </span>
                                                    <span class="inline-flex shrink-0 items-center gap-1 font-medium {{ $cor }}">
                                                        <flux:icon :icon="$icone" variant="micro" class="size-3.5" /> {{ $txt }}
                                                    </span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="closeTester" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Fechar</button>
                    <button type="button" wire:click="runTest" class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-zinc-900">
                        <flux:icon icon="beaker" variant="micro" wire:loading.remove wire:target="runTest" />
                        <flux:icon icon="arrow-path" variant="micro" class="animate-spin" wire:loading wire:target="runTest" />
                        Testar
                    </button>
                </div>
            </x-slot:footer>
        </x-modal>
    @endif

    {{-- MODAL: confirmar exclusao --}}
    @if ($deleting)
        <x-modal wireClose="cancelDelete" title="Excluir regra">
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                Apagar a regra com gatilho <strong>"{{ $deleting->triggerList()->first()['value'] ?? '' }}"</strong>?
                Esta acao nao pode ser desfeita.
            </p>
            <div class="flex justify-end gap-2 pt-4">
                <button type="button" wire:click="cancelDelete" data-autofocus class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                <button type="button" wire:click="deleteConfirmed" class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                    <flux:icon icon="trash" variant="micro" /> Excluir
                </button>
            </div>
        </x-modal>
    @endif
</div>
