<div class="h-full overflow-y-auto">
    <div class="mx-auto max-w-4xl p-6 space-y-4">
        <div class="flex items-center justify-between gap-3">
            <h1 class="text-xl font-semibold">Sugestoes da IA (aprovacao)</h1>
        </div>

        <p class="text-sm text-zinc-500">
            O que a IA <strong>escalou</strong> (nao respondeu sozinha) cai aqui. Nada e enviado sem
            o seu clique. Enviar/Editar sai pelo caminho normal de envio (freios protetivos valem;
            contato "off" nunca recebe). Senhas aparecem mascaradas e so sao resolvidas no envio.
        </p>

        {{-- Filtros --}}
        <div class="flex flex-wrap items-center gap-1.5 text-sm">
            @foreach (['pendentes' => 'Pendentes', 'decididas' => 'Decididas', 'expiradas' => 'Expiradas', 'decisoes' => 'Decisoes da IA'] as $f => $label)
                <button type="button" wire:click="setFilter('{{ $f }}')"
                    @class([
                        'rounded-full px-3 py-1.5 font-medium transition',
                        'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $filter === $f,
                        'border border-zinc-300 hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800' => $filter !== $f,
                    ])>{{ $label }}</button>
            @endforeach
        </div>

        @if ($filter === 'decisoes')
            {{-- Aba de auditoria: decisoes recentes da IA (somente leitura) --}}
            <div class="rounded-xl border border-zinc-200 bg-white divide-y divide-zinc-100 dark:border-zinc-800 dark:bg-zinc-900 dark:divide-zinc-800">
                @forelse ($decisoes as $d)
                    <div class="flex items-start gap-3 p-3 text-sm" wire:key="d-{{ $d->id }}">
                        <span @class([
                            'mt-0.5 inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-xs font-medium',
                            'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' => $d->acao === 'respondeu',
                            'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300' => $d->acao === 'escalou',
                            'bg-zinc-200 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' => $d->acao === 'silenciou',
                        ])>{{ $d->acao }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                <span class="font-medium">{{ $d->contact?->push_name ?: \Illuminate\Support\Str::before($d->remote_jid, '@') }}</span>
                                <span class="text-xs text-zinc-400">{{ $d->created_at->timezone(config('app.display_timezone'))->format('d/m H:i') }}</span>
                                <span class="rounded bg-zinc-100 px-1.5 text-[10px] text-zinc-500 dark:bg-zinc-800">origem: {{ $d->origem }}</span>
                                @if ($d->motivo)
                                    <span class="rounded bg-zinc-100 px-1.5 text-[10px] text-zinc-500 dark:bg-zinc-800">{{ $d->motivo }}</span>
                                @endif
                                @if ($d->confidence !== null)
                                    <span class="rounded bg-zinc-100 px-1.5 text-[10px] text-zinc-500 dark:bg-zinc-800">{{ number_format($d->confidence * 100) }}%</span>
                                @endif
                            </div>
                            @if ($d->intent)
                                <div class="text-xs text-zinc-500">intent: {{ $d->intent }}</div>
                            @endif
                            @if ($d->resposta_resumo)
                                <div class="mt-0.5 truncate text-xs text-zinc-400">{{ $d->resposta_resumo }}</div>
                            @endif
                            {{-- Fatia 4: promocao a partir do que a IA respondeu sozinha --}}
                            @if ($d->acao === 'respondeu')
                                <div class="mt-1.5 flex flex-wrap items-center gap-2">
                                    @if ($d->isPromoted())
                                        <span class="inline-flex items-center gap-1 rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-medium text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">
                                            <flux:icon icon="academic-cap" variant="micro" class="size-3" />
                                            {{ $d->promoted_rule_id ? 'virou regra #' . $d->promoted_rule_id : 'virou entrada #' . $d->promoted_knowledge_id }}
                                        </span>
                                    @else
                                        <button type="button" wire:click="startPromote('regra', 'decisao', {{ $d->id }})"
                                            class="inline-flex items-center gap-1 rounded-lg border border-indigo-300 px-2 py-1 text-xs font-medium text-indigo-700 hover:bg-indigo-50 dark:border-indigo-800 dark:text-indigo-300 dark:hover:bg-indigo-950/40">
                                            <flux:icon icon="bolt" variant="micro" class="size-3" /> Virar regra
                                        </button>
                                        <button type="button" wire:click="startPromote('base', 'decisao', {{ $d->id }})"
                                            class="inline-flex items-center gap-1 rounded-lg border border-indigo-300 px-2 py-1 text-xs font-medium text-indigo-700 hover:bg-indigo-50 dark:border-indigo-800 dark:text-indigo-300 dark:hover:bg-indigo-950/40">
                                            <flux:icon icon="book-open" variant="micro" class="size-3" /> Virar entrada
                                        </button>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="flex flex-col items-center gap-2 p-10 text-center text-zinc-400">
                        <flux:icon icon="sparkles" class="size-8" />
                        <p class="text-sm">Nenhuma decisao da IA ainda.</p>
                    </div>
                @endforelse
            </div>
        @else
            {{-- Fila de pendencias --}}
            <div class="rounded-xl border border-zinc-200 bg-white divide-y divide-zinc-100 dark:border-zinc-800 dark:bg-zinc-900 dark:divide-zinc-800">
                @forelse ($itens as $p)
                    @php
                        $nome = $p->contact?->push_name ?: \Illuminate\Support\Str::before($p->remote_jid, '@');
                        $janela = $janelas[$p->id] ?? null; // CH-2: so canal oficial
                        $sugestaoMask = $p->suggested_response ? $vault->mask($p->suggested_response) : null;
                    @endphp
                    <div class="p-3 space-y-2" wire:key="p-{{ $p->id }}">
                        <div class="flex items-center gap-2">
                            <div class="flex size-8 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-600 dark:bg-amber-950">
                                <flux:icon icon="inbox" variant="micro" />
                            </div>
                            <span class="min-w-0 truncate font-medium">{{ $nome }}</span>
                            <span class="shrink-0 text-xs text-zinc-400">{{ $p->created_at->diffForHumans() }}</span>
                            <span class="ml-auto flex shrink-0 items-center gap-1.5">
                                @if ($p->status !== 'pending')
                                    <span @class([
                                        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                        'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' => in_array($p->status, ['approved', 'edited'], true),
                                        'bg-zinc-200 text-zinc-500 dark:bg-zinc-800' => in_array($p->status, ['rejected', 'expired'], true),
                                    ])>{{ ['approved' => 'enviada', 'edited' => 'enviada (editada)', 'rejected' => 'ignorada', 'expired' => 'expirada'][$p->status] ?? $p->status }}</span>
                                @endif
                            </span>
                        </div>

                        {{-- Mensagem original --}}
                        <div class="rounded-lg bg-zinc-50 px-3 py-2 text-sm dark:bg-zinc-800/50">
                            <span class="text-[10px] uppercase tracking-wide text-zinc-400">Mensagem do contato</span>
                            <div class="whitespace-pre-wrap break-words">{{ \Illuminate\Support\Str::limit((string) ($p->incomingMessage?->text ?? '(mensagem indisponivel)'), 300) }}</div>
                        </div>

                        {{-- Sugestao (mascarada) --}}
                        <div class="rounded-lg border border-indigo-200 bg-indigo-50/40 px-3 py-2 text-sm dark:border-indigo-900 dark:bg-indigo-950/20">
                            <span class="text-[10px] uppercase tracking-wide text-zinc-400">Sugestao da IA</span>
                            @if ($sugestaoMask)
                                <div class="whitespace-pre-wrap break-words">{{ $sugestaoMask }}</div>
                            @else
                                <div class="text-zinc-400">— sem sugestao (escreva a resposta em Editar) —</div>
                            @endif
                        </div>

                        <div class="flex flex-wrap items-center gap-1.5 text-[10px] text-zinc-500">
                            <span class="rounded bg-zinc-100 px-1.5 py-0.5 dark:bg-zinc-800">origem: {{ $p->origin }}</span>
                            @if ($p->reason)
                                <span class="rounded bg-amber-50 px-1.5 py-0.5 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300">{{ \App\Livewire\Revisao::MOTIVOS[$p->reason] ?? $p->reason }}</span>
                            @endif
                            @if ($p->confidence !== null)
                                <span class="rounded bg-zinc-100 px-1.5 py-0.5 dark:bg-zinc-800">confianca: {{ number_format($p->confidence * 100) }}%</span>
                            @endif
                            @if ($p->intent)
                                <span class="rounded bg-zinc-100 px-1.5 py-0.5 dark:bg-zinc-800">intent: {{ $p->intent }}</span>
                            @endif
                        </div>

                        @if ($janela !== null)
                            <p @class([
                                'mt-1 flex items-center gap-1 text-[11px]',
                                'text-zinc-400' => $janela !== 'FECHADA',
                                'text-red-500' => $janela === 'FECHADA',
                            ])>
                                <flux:icon icon="clock" variant="micro" class="size-3" />
                                Canal oficial (Meta): janela de 24h {{ $janela === 'FECHADA' ? 'FECHADA — aprovar agora sera bloqueado (janela_24h); este canal exige template pra iniciar (CH-3)' : 'aberta — resta ' . $janela }}
                            </p>
                        @endif
                        {{-- Acoes de envio (so pendente e dentro da validade) + promocao (Fatia 4) --}}
                        <div class="flex flex-wrap items-center gap-2 pt-1">
                            @if ($p->isActionable())
                                @if (trim((string) $p->suggested_response) !== '')
                                    <button type="button" wire:click="askSend({{ $p->id }})"
                                        class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700">
                                        <flux:icon icon="paper-airplane" variant="micro" /> Enviar
                                    </button>
                                @endif
                                <button type="button" wire:click="startEdit({{ $p->id }})"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 px-3 py-1.5 text-sm font-medium hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">
                                    <flux:icon icon="pencil-square" variant="micro" /> Editar
                                </button>
                                <button type="button" wire:click="ignore({{ $p->id }})"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 px-3 py-1.5 text-sm font-medium text-zinc-500 hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">
                                    <flux:icon icon="x-mark" variant="micro" /> Ignorar
                                </button>
                            @endif

                            @if ($p->isPromoted())
                                <span class="inline-flex items-center gap-1 rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">
                                    <flux:icon icon="academic-cap" variant="micro" class="size-3" />
                                    {{ $p->promoted_rule_id ? 'virou regra #' . $p->promoted_rule_id : 'virou entrada #' . $p->promoted_knowledge_id }}
                                </span>
                            @else
                                <button type="button" wire:click="startPromote('regra', 'pendencia', {{ $p->id }})"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-indigo-300 px-3 py-1.5 text-sm font-medium text-indigo-700 hover:bg-indigo-50 dark:border-indigo-800 dark:text-indigo-300 dark:hover:bg-indigo-950/40">
                                    <flux:icon icon="bolt" variant="micro" /> Virar regra
                                </button>
                                <button type="button" wire:click="startPromote('base', 'pendencia', {{ $p->id }})"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-indigo-300 px-3 py-1.5 text-sm font-medium text-indigo-700 hover:bg-indigo-50 dark:border-indigo-800 dark:text-indigo-300 dark:hover:bg-indigo-950/40">
                                    <flux:icon icon="book-open" variant="micro" /> Virar entrada da base
                                </button>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="flex flex-col items-center gap-2 p-10 text-center text-zinc-400">
                        <flux:icon icon="inbox" class="size-8" />
                        <p class="text-sm">
                            @if ($filter === 'pendentes') Nenhuma pendencia. Quando a IA escalar algo, aparece aqui.
                            @elseif ($filter === 'decididas') Nenhuma pendencia decidida ainda.
                            @else Nenhuma pendencia expirada.
                            @endif
                        </p>
                    </div>
                @endforelse
            </div>
        @endif
    </div>

    {{-- MODAL: confirmar envio da sugestao --}}
    @if ($sending)
        <x-modal wireClose="cancelSend" title="Enviar resposta sugerida">
            <div class="space-y-3 text-sm">
                <p class="text-zinc-600 dark:text-zinc-300">
                    Enviar para <strong>{{ $sending->contact?->push_name ?: \Illuminate\Support\Str::before($sending->remote_jid, '@') }}</strong>:
                </p>
                <div class="rounded-lg bg-zinc-50 px-3 py-2 whitespace-pre-wrap break-words dark:bg-zinc-800/50">{{ $vault->mask((string) $sending->suggested_response) }}</div>
                <p class="text-[11px] text-zinc-400">
                    Placeholders ({{ '{nome}' }}, {{ '{saudacao}' }}, {{ '{senha:...}' }}) sao resolvidos no envio.
                    O envio respeita os freios protetivos; contato "off" nao recebe.
                </p>
            </div>
            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="cancelSend" data-autofocus class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                    <button type="button" wire:click="confirmSend" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                        <flux:icon icon="paper-airplane" variant="micro" wire:loading.remove wire:target="confirmSend" />
                        <flux:icon icon="arrow-path" variant="micro" class="animate-spin" wire:loading wire:target="confirmSend" />
                        Enviar agora
                    </button>
                </div>
            </x-slot:footer>
        </x-modal>
    @endif

    {{-- MODAL: virar regra (Fatia 4) --}}
    @if ($promoteKind === 'regra')
        <x-modal wireClose="cancelPromote" title="Virar regra deterministica" maxWidth="lg">
            <div class="space-y-3">
                <p class="text-xs text-zinc-500">
                    Cria uma regra em /regras a partir desta conversa: da proxima vez a resposta e
                    <strong>instantanea e sem IA</strong>. Mesmas validacoes e guardas do cadastro normal.
                </p>
                <div>
                    <label class="mb-1 block text-xs font-medium">Gatilho</label>
                    <div class="flex gap-2">
                        <select wire:model="pTriggerType" class="w-36 shrink-0 rounded-lg border border-zinc-300 bg-white px-2 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                            <option value="contains">Contem</option>
                            <option value="exact">Mensagem exata</option>
                            <option value="starts_with">Comeca com</option>
                        </select>
                        <input type="text" wire:model="pTrigger" data-autofocus
                            class="min-w-0 flex-1 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    </div>
                    @error('pTrigger') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    <p class="mt-1 text-[11px] text-zinc-400">Pre-preenchido com a mensagem original — ajuste pra palavra-chave se quiser casar variacoes.</p>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Resposta</label>
                    <textarea wire:model="pResponse" rows="3"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"></textarea>
                    @error('pResponse') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Escopo</label>
                    <div class="flex items-center gap-4 text-sm">
                        <label class="inline-flex items-center gap-1.5"><input type="radio" wire:model.live="pScope" value="contatos"> So este contato</label>
                        <label class="inline-flex items-center gap-1.5"><input type="radio" wire:model.live="pScope" value="global"> Todos os Aprovados</label>
                    </div>
                    @error('pScope') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    <p class="mt-1 text-[11px] text-zinc-400">Default conservador: so o contato desta conversa. Resposta com {{ '{senha:...}' }} NUNCA pode ser global.</p>
                </div>
                <label class="inline-flex items-start gap-2 text-sm">
                    <input type="checkbox" wire:model="pAiMatch" class="mt-0.5 rounded border-zinc-300 dark:border-zinc-700">
                    <span>Permitir a IA casar mensagens parecidas
                        <span class="block text-[11px] text-zinc-400">A mensagem original entra como frase-exemplo da intencao — o aprendizado alimenta o casamento por IA.</span>
                    </span>
                </label>
            </div>
            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="cancelPromote" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                    <button type="button" wire:click="confirmPromoteRule" class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                        <flux:icon icon="bolt" variant="micro" wire:loading.remove wire:target="confirmPromoteRule" />
                        <flux:icon icon="arrow-path" variant="micro" class="animate-spin" wire:loading wire:target="confirmPromoteRule" />
                        Criar regra
                    </button>
                </div>
            </x-slot:footer>
        </x-modal>
    @endif

    {{-- MODAL: virar entrada da base (Fatia 4) --}}
    @if ($promoteKind === 'base')
        <x-modal wireClose="cancelPromote" title="Virar entrada da base de conhecimento" maxWidth="lg">
            <div class="space-y-3">
                <p class="text-xs text-zinc-500">
                    Cria uma entrada em /conhecimento: a IA passa a responder isso <strong>fundamentada</strong>
                    (contatos em modo conhecimento). Mesmas guardas do cadastro normal.
                </p>
                <div>
                    <label class="mb-1 block text-xs font-medium">Titulo</label>
                    <input type="text" wire:model="pTitle" data-autofocus
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    @error('pTitle') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Conteudo</label>
                    <textarea wire:model="pContent" rows="4"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"></textarea>
                    @error('pContent') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 flex items-center gap-1 text-xs font-medium">
                        Sensibilidade
                        <x-info-tip text="low/medium: vai ao modelo e pode ser respondido automaticamente. high: NUNCA vai ao modelo nem e respondido direto — escala pra sua revisao." />
                    </label>
                    <select wire:model="pSensitivity" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                        <option value="low">low — publico</option>
                        <option value="medium">medium — interno comum</option>
                        <option value="high">high — sensivel (nunca vai ao modelo)</option>
                    </select>
                </div>
                <label class="inline-flex items-start gap-2 text-sm">
                    <input type="checkbox" wire:model="pRestrict" class="mt-0.5 rounded border-zinc-300 dark:border-zinc-700">
                    <span>Restringir ao contato desta conversa
                        <span class="block text-[11px] text-zinc-400">Desmarcado = vale pra todos os contatos com IA em modo conhecimento. Conteudo com {{ '{senha:...}' }} exige restricao. Ajuste fino de contatos em /conhecimento.</span>
                    </span>
                </label>
                @error('pRestrict') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
            </div>
            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="cancelPromote" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                    <button type="button" wire:click="confirmPromoteKnowledge" class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                        <flux:icon icon="book-open" variant="micro" wire:loading.remove wire:target="confirmPromoteKnowledge" />
                        <flux:icon icon="arrow-path" variant="micro" class="animate-spin" wire:loading wire:target="confirmPromoteKnowledge" />
                        Criar entrada
                    </button>
                </div>
            </x-slot:footer>
        </x-modal>
    @endif

    {{-- MODAL: editar e enviar --}}
    @if ($editing)
        <x-modal wireClose="cancelEdit" title="Editar e enviar" maxWidth="lg">
            <div class="space-y-3">
                <p class="text-sm text-zinc-600 dark:text-zinc-300">
                    Resposta para <strong>{{ $editing->contact?->push_name ?: \Illuminate\Support\Str::before($editing->remote_jid, '@') }}</strong>
                    (mensagem: "{{ \Illuminate\Support\Str::limit((string) ($editing->incomingMessage?->text ?? ''), 80) }}")
                </p>
                <textarea wire:model="editText" rows="4" data-autofocus placeholder="Escreva a resposta..."
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"></textarea>
                @error('editText') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                <p class="text-[11px] text-zinc-400">
                    Placeholders valem ({{ '{nome}' }}, {{ '{saudacao}' }}, {{ '{data}' }}, {{ '{hora}' }}) e sao resolvidos
                    no envio. Nao e permitido inserir {{ '{senha:...}' }} novo — so manter o que ja veio na sugestao.
                </p>
            </div>
            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="cancelEdit" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                    <button type="button" wire:click="confirmEdit" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                        <flux:icon icon="paper-airplane" variant="micro" wire:loading.remove wire:target="confirmEdit" />
                        <flux:icon icon="arrow-path" variant="micro" class="animate-spin" wire:loading wire:target="confirmEdit" />
                        Enviar editado
                    </button>
                </div>
            </x-slot:footer>
        </x-modal>
    @endif
</div>
