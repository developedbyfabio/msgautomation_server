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
            ajuda anti-ban). <strong>Contem</strong> casa palavra inteira; acento/maiusculas ignorados.
            Primeira regra (de cima) que casa vence.
        </p>

        <div class="rounded-xl border border-zinc-200 bg-white divide-y divide-zinc-100 dark:border-zinc-800 dark:bg-zinc-900 dark:divide-zinc-800">
            @forelse ($rules as $rule)
                @php $trigs = $rule->triggerList(); $resps = $rule->responseList(); @endphp
                <div class="flex items-start gap-3 p-3" wire:key="r-{{ $rule->id }}">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-1.5">
                            @foreach ($trigs as $t)
                                <span class="inline-flex items-center gap-1 rounded-md bg-zinc-100 px-1.5 py-0.5 text-xs dark:bg-zinc-800">
                                    <span class="text-[10px] text-zinc-400">{{ \App\Whatsapp\AutoReply\RuleMatcher::typeLabel($t['type']) }}</span>
                                    <span class="font-medium">{{ $t['value'] }}</span>
                                </span>
                            @endforeach
                        </div>
                        @if (! empty($conflicts[$rule->id]))
                            @php $confLabels = collect($conflicts[$rule->id])->pluck('label')->unique()->take(3)->implode(', '); @endphp
                            <div class="mt-1 inline-flex items-center gap-1 rounded bg-amber-50 px-1.5 py-0.5 text-[10px] text-amber-700 dark:bg-amber-950/50 dark:text-amber-300">
                                <flux:icon icon="exclamation-triangle" variant="micro" class="size-3" />
                                Sobreposicao: casa as mesmas mensagens de "{{ $confLabels }}". A mais especifica vence; ajuste se nao for o que quer.
                            </div>
                        @endif
                        @if (! empty($flowOverlap[$rule->id]))
                            <div class="mt-1 inline-flex items-center gap-1 rounded bg-sky-50 px-1.5 py-0.5 text-[10px] text-sky-700 dark:bg-sky-950/50 dark:text-sky-300">
                                <flux:icon icon="rectangle-stack" variant="micro" class="size-3" />
                                Fluxo "{{ implode(', ', array_slice($flowOverlap[$rule->id], 0, 2)) }}" intercepta estas mensagens (o fluxo vence a regra).
                            </div>
                        @endif
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
                            @elseif ($rule->scope === 'tags')
                                <span class="inline-flex items-center gap-1 rounded bg-purple-100 px-1.5 py-0.5 text-purple-700 dark:bg-purple-950 dark:text-purple-300"><flux:icon icon="tag" variant="micro" class="size-3" /> tag: {{ $rule->tags->pluck('name')->implode(', ') }}</span>
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
                    @error('triggers') <p class="mb-2 text-xs text-red-500">{{ $message }}</p> @enderror
                    <div class="space-y-2">
                        @foreach ($triggers as $i => $t)
                            {{-- S4: [tipo] [precisao] [texto] [x] na MESMA linha. --}}
                            <div wire:key="trg-{{ $i }}" class="flex flex-wrap items-start gap-2 sm:flex-nowrap">
                                <select wire:model.live="triggers.{{ $i }}.type" class="w-28 shrink-0 rounded-lg border border-zinc-300 bg-white px-2 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                                    <option value="contains">Contem</option>
                                    <option value="exact">Mensagem exata</option>
                                    <option value="starts_with">Comeca com</option>
                                    <option value="regex">Regex (avancado)</option>
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
                                    {{-- S3: picker de senha (insere {senha:nome}; so nomes, nunca valores) --}}
                                    @if (! empty($secretNames))
                                        <flux:dropdown position="bottom" align="start">
                                            <button type="button" class="mt-1 inline-flex items-center gap-1 text-xs text-emerald-600 hover:underline">
                                                <flux:icon icon="key" variant="micro" /> inserir senha
                                            </button>
                                            <flux:menu>
                                                @foreach ($secretNames as $sn)
                                                    <flux:menu.item wire:click="insertSecret({{ $i }}, '{{ $sn }}')">{{ $sn }}</flux:menu.item>
                                                @endforeach
                                            </flux:menu>
                                        </flux:dropdown>
                                    @endif
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
                    <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">{hora}</code> ·
                    <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">{senha:nome}</code> senha do cofre (resolvida no envio; exige escopo de contatos)
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
                    <p class="mt-1 text-[11px] text-zinc-400">
                        Escolher uma frequencia especifica <strong>substitui o "Intervalo por contato" global</strong> para esta regra.
                        "Sempre" = sem limite por contato. Os tetos de volume (intervalo minimo, por minuto, por dia) continuam valendo.
                    </p>
                </div>

                {{-- ESCOPO (S2 textos + S3 checkboxes) --}}
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-zinc-400">Escopo</label>
                    <div class="flex flex-wrap items-center gap-4 text-sm">
                        <label class="inline-flex items-center gap-1.5"><input type="radio" wire:model.live="scope" value="global"> Todos os Aprovados</label>
                        <label class="inline-flex items-center gap-1.5"><input type="radio" wire:model.live="scope" value="contatos"> Contatos Especificos</label>
                        <label class="inline-flex items-center gap-1.5"><input type="radio" wire:model.live="scope" value="tags"> Contatos com tag
                            <x-info-tip text="Casa quem tem QUALQUER uma das tags marcadas, avaliado na hora da mensagem (tag entra/sai, o alcance muda na proxima). Regra com {senha:} NAO pode usar tag — segredo exige lista explicita de contatos." />
                        </label>
                    </div>
                    @error('scope') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
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
                    @if ($scope === 'tags')
                        <div class="mt-2 flex flex-wrap gap-2 rounded-lg border border-zinc-200 p-2 dark:border-zinc-700">
                            @forelse ($allTags as $t)
                                <label class="inline-flex cursor-pointer items-center gap-1.5 text-sm" wire:key="rtag-{{ $t->id }}">
                                    <input type="checkbox" value="{{ $t->id }}" wire:model.live="scopeTagIds" class="rounded border-zinc-300 dark:border-zinc-700">
                                    <x-tag-chip :color="$t->color" small>{{ $t->name }}</x-tag-chip>
                                </label>
                            @empty
                                <p class="text-xs text-zinc-400">Nenhuma tag ainda. Crie no painel de um contato (/contatos).</p>
                            @endforelse
                        </div>
                        <p class="mt-1 text-[11px] text-zinc-400">Casa quem tem QUALQUER uma das tags (avaliado a cada mensagem).</p>
                        @error('scopeTagIds') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                    @endif
                </div>

                {{-- IA CASA PARECIDAS (Camada 3) --}}
                <div class="rounded-lg border border-indigo-200 bg-indigo-50/40 p-3 dark:border-indigo-900 dark:bg-indigo-950/20">
                    <label class="inline-flex items-center gap-2 text-sm font-medium">
                        <input type="checkbox" wire:model.live="aiMatchEnabled" class="rounded border-zinc-300 dark:border-zinc-700">
                        <flux:icon icon="sparkles" variant="micro" class="text-indigo-500" /> Permitir a IA casar mensagens parecidas
                    </label>
                    <p class="mt-1 text-[11px] text-zinc-500">
                        Quando nenhuma regra casar exatamente, a IA pode identificar que uma mensagem tem a
                        <strong>mesma intencao</strong> desta regra (ex.: "me fala a hora ai" -> "que horas sao?") e
                        responder com <strong>a resposta desta regra</strong>. So funciona com o kill switch da IA
                        e a IA do contato ligados. A IA nunca inventa texto.
                    </p>
                    @if ($aiMatchEnabled)
                        <div class="mt-3">
                            <div class="mb-1 flex items-center justify-between">
                                <label class="text-[11px] font-semibold uppercase tracking-wide text-zinc-400">Frases-exemplo (opcional)</label>
                                <button type="button" wire:click="addAiExample" class="inline-flex items-center gap-1 text-xs text-indigo-600 hover:underline">
                                    <flux:icon icon="plus" variant="micro" /> exemplo
                                </button>
                            </div>
                            <p class="mb-2 text-[11px] text-zinc-400">Exemplos de como o contato pode pedir isto (ajuda a IA a acertar). Nunca coloque senha/valor aqui — sao exemplos de MENSAGEM.</p>
                            @forelse ($aiExamples as $i => $ex)
                                <div wire:key="aiex-{{ $i }}" class="mb-2 flex items-center gap-2">
                                    <input type="text" wire:model="aiExamples.{{ $i }}" placeholder="ex.: me fala a hora ai"
                                        class="min-w-0 flex-1 rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                                    <button type="button" wire:click="removeAiExample({{ $i }})" class="text-zinc-400 hover:text-red-500" aria-label="Remover exemplo">
                                        <flux:icon icon="x-mark" variant="micro" />
                                    </button>
                                </div>
                            @empty
                                <p class="text-[11px] text-zinc-400">Sem exemplos. A IA usa os gatilhos acima como referencia da intencao.</p>
                            @endforelse
                        </div>
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
                            <p class="flex items-center gap-1.5 text-zinc-500"><flux:icon icon="x-circle" variant="micro" /> Nenhuma regra casaria (determinístico).</p>
                            @if (! empty($testResult['fora_por_tag']))
                                <p class="mt-1 flex items-start gap-1.5 text-[11px] text-purple-600 dark:text-purple-300">
                                    <flux:icon icon="tag" variant="micro" class="mt-0.5 size-3 shrink-0" />
                                    <span>Gatilho casaria, mas o contato NAO tem a tag: {{ implode(' · ', $testResult['fora_por_tag']) }}</span>
                                </p>
                            @endif
                            @php $ai = $testResult['ai'] ?? null; @endphp
                            @if ($ai)
                                <div class="mt-2 rounded-lg border border-indigo-200 bg-indigo-50/50 p-2 text-xs dark:border-indigo-900 dark:bg-indigo-950/30">
                                    @if ($ai['contato_ligada'] && $ai['global_ligada'] && ($ai['candidatas'] > 0 || ($ai['base_candidatas'] ?? 0) > 0))
                                        @if ($ai['candidatas'] > 0)
                                            <p class="flex items-center gap-1.5 text-indigo-700 dark:text-indigo-300">
                                                <flux:icon icon="sparkles" variant="micro" /> A IA classificaria esta mensagem ({{ $ai['candidatas'] }} regra(s) candidata(s), modo {{ $ai['modo'] }}).
                                            </p>
                                            <p class="mt-0.5 text-[11px] text-zinc-500">Se reconhecer a intencao com confianca suficiente, responde com a resposta da regra; senao, escala. (Este teste nao chama a IA.)</p>
                                        @endif
                                        @if ($ai['modo'] === 'conhecimento' && ($ai['base_candidatas'] ?? 0) > 0)
                                            <p class="flex items-center gap-1.5 {{ $ai['candidatas'] > 0 ? 'mt-1.5' : '' }} text-indigo-700 dark:text-indigo-300">
                                                <flux:icon icon="book-open" variant="micro" /> Elegivel pra base de conhecimento ({{ $ai['base_candidatas'] }} entrada(s) candidata(s)).
                                            </p>
                                            <p class="mt-0.5 text-[11px] text-zinc-500">Quando nenhuma regra casar (nem por IA), a IA tenta responder fundamentada SO nessas entradas (low/medium permitidas). Sem fundamento, silencia. (Este teste nao chama a IA.)</p>
                                        @elseif ($ai['modo'] === 'conhecimento')
                                            <p class="flex items-center gap-1.5 mt-1.5 text-zinc-500"><flux:icon icon="book-open" variant="micro" /> Modo conhecimento, mas nenhuma entrada da base e candidata pra este contato (ativa, permitida, low/medium).</p>
                                        @endif
                                        @php $temaLabels = \App\Livewire\Configuracoes::AI_TOPIC_LABELS; @endphp
                                        <p class="mt-1.5 text-[11px] text-zinc-400">
                                            Config vigente: limiar <strong>{{ number_format($ai['limiar'] ?? 0.75, 2) }}</strong> (abaixo escala) ·
                                            temas com aprovacao: {{ ! empty($ai['temas']) ? collect($ai['temas'])->map(fn ($t) => $temaLabels[$t] ?? $t)->implode(', ') : 'nenhum' }}.
                                        </p>
                                    @elseif ($ai['contato_ligada'] && ! $ai['global_ligada'])
                                        <p class="flex items-center gap-1.5 text-zinc-500"><flux:icon icon="sparkles" variant="micro" /> IA ligada no contato, mas o kill switch da IA esta OFF (Configuracoes). Nao atuaria.</p>
                                    @elseif ($ai['contato_ligada'] && $ai['candidatas'] === 0)
                                        <p class="flex items-center gap-1.5 text-zinc-500"><flux:icon icon="sparkles" variant="micro" /> IA ligada, mas nenhuma regra tem "IA casa parecidas" habilitada{{ $ai['modo'] === 'conhecimento' ? ' e nao ha entrada candidata na base' : '' }}. Nao ha o que casar.</p>
                                    @else
                                        <p class="flex items-center gap-1.5 text-zinc-400"><flux:icon icon="sparkles" variant="micro" /> IA desligada para este contato.</p>
                                    @endif
                                </div>
                            @else
                                <p class="mt-1 text-[11px] text-zinc-400">Escolha um contato para ver se a IA atuaria.</p>
                            @endif
                        @else
                            <div class="space-y-1.5">
                                <p class="flex items-center gap-1.5 font-medium text-emerald-700 dark:text-emerald-300">
                                    <flux:icon icon="check-circle" variant="micro" /> Casaria a regra #{{ $testResult['rule_id'] }}
                                </p>
                                @if ($testResult['casou_por_tag'] ?? null)
                                    <p class="flex items-center gap-1.5 text-[11px] text-purple-600 dark:text-purple-300">
                                        <flux:icon icon="tag" variant="micro" class="size-3" /> Casou por TAG (contato tem: {{ $testResult['casou_por_tag'] }}).
                                    </p>
                                @endif
                                @if (! empty($testResult['fora_por_tag']))
                                    <p class="flex items-start gap-1.5 text-[11px] text-purple-600 dark:text-purple-300">
                                        <flux:icon icon="tag" variant="micro" class="mt-0.5 size-3 shrink-0" />
                                        <span>Fora por tag: {{ implode(' · ', $testResult['fora_por_tag']) }}</span>
                                    </p>
                                @endif
                                <p class="text-xs text-zinc-500">Gatilho: <span class="font-mono">{{ $testResult['trigger'] }}</span>
                                    @if (($testResult['trigger_precision'] ?? 'exato') !== 'exato')
                                        <span class="rounded bg-amber-100 px-1 text-amber-700 dark:bg-amber-950 dark:text-amber-300">tolerante</span>
                                    @endif
                                </p>
                                @if (! empty($testResult['tambem']))
                                    <p class="flex items-start gap-1.5 text-[11px] text-amber-600 dark:text-amber-400">
                                        <flux:icon icon="exclamation-triangle" variant="micro" class="mt-0.5 size-3 shrink-0" />
                                        <span>Tambem casariam (venceu a mais especifica): {{ implode(', ', $testResult['tambem']) }}</span>
                                    </p>
                                @endif
                                <div class="rounded bg-white p-2 text-sm dark:bg-zinc-900">
                                    <div class="flex items-center justify-between">
                                        <span class="text-[11px] uppercase text-zinc-400">Resposta</span>
                                        @if ($testResult['tem_senha'] ?? false)
                                            @if ($testReveal)
                                                <button type="button" wire:click="runTest" class="text-[11px] text-zinc-400 hover:underline">ocultar senha</button>
                                            @else
                                                <button type="button" wire:click="revealTest" class="text-[11px] text-emerald-600 hover:underline">revelar senha</button>
                                            @endif
                                        @endif
                                    </div>
                                    <div class="whitespace-pre-wrap">{{ $testResult['resposta'] }}</div>
                                    @if (($testResult['respostas_total'] ?? 1) > 1)
                                        <p class="mt-1 text-[11px] text-zinc-400">(sorteia entre {{ $testResult['respostas_total'] }} respostas — mostrando a 1a)</p>
                                    @endif
                                    @if (($testResult['tem_senha'] ?? false) && ! $testReveal)
                                        <p class="mt-1 text-[11px] text-zinc-400">Senha mascarada. "Revelar senha" decifra so pra exibir (nao persiste).</p>
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
