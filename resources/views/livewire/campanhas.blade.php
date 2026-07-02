<div class="h-full overflow-y-auto">
    <div class="mx-auto max-w-4xl p-6 space-y-4">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-1">
                <h1 class="text-xl font-semibold">Campanhas proativas</h1>
                <x-info-tip text="Campanhas so disparam com o interruptor de proativas ligado (/configuracoes), dentro da janela e dos tetos — e o DISPARO em si chega na proxima fatia. Fluxo: rascunho -> preview da lista exata -> sua aprovacao (congela tudo). Publico: SO contatos com opt-in." />
            </div>
            <button type="button" wire:click="novo"
                class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-3 py-2 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
                <flux:icon icon="plus" variant="micro" /> Nova campanha
            </button>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white divide-y divide-zinc-100 dark:border-zinc-800 dark:bg-zinc-900 dark:divide-zinc-800">
            @forelse ($campanhas as $c)
                <div class="flex items-start gap-3 p-3" wire:key="camp-{{ $c->id }}">
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-rose-100 text-rose-500 dark:bg-rose-950">
                        <flux:icon icon="megaphone" variant="micro" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="truncate font-medium">{{ $c->name }}</span>
                            <span @class([
                                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                'bg-zinc-200 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' => $c->status === 'draft',
                                'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300' => $c->status === 'previewed',
                                'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' => in_array($c->status, ['approved', 'running'], true),
                                'bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300' => $c->status === 'done',
                                'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300' => $c->status === 'paused',
                                'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' => $c->status === 'cancelled',
                            ])>{{ ['draft' => 'rascunho', 'previewed' => 'em preview', 'approved' => 'aprovada', 'running' => 'em andamento', 'done' => 'concluida', 'paused' => 'pausada', 'cancelled' => 'cancelada'][$c->status] ?? $c->status }}</span>
                            @if (in_array($c->status, ['approved', 'running'], true) && ! $proativasLigadas)
                                <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-950 dark:text-amber-300" title="O disparo so acontece com o interruptor de proativas LIGADO em /configuracoes.">
                                    <flux:icon icon="pause" variant="micro" class="size-3" /> aguardando interruptor
                                </span>
                            @endif
                        </div>
                        <div class="mt-0.5 truncate text-sm text-zinc-500">{{ \Illuminate\Support\Str::limit($c->message, 100) }}</div>
                        <div class="mt-1 flex flex-wrap items-center gap-1.5 text-[10px] text-zinc-400">
                            <span class="rounded bg-zinc-100 px-1.5 py-0.5 dark:bg-zinc-800">publico: {{ ['tags' => 'por tag', 'coluna_kanban' => 'coluna do Kanban', 'contatos' => 'contatos especificos'][$c->audience_type] ?? $c->audience_type }}</span>
                            @if ($c->status === 'approved')
                                <span class="rounded bg-emerald-50 px-1.5 py-0.5 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300">{{ $c->pendentes_count }}/{{ $c->total_count }} agendado(s)</span>
                                <span class="rounded bg-zinc-100 px-1.5 py-0.5 dark:bg-zinc-800">aprovada {{ $c->approved_at?->paraExibicao()->format('d/m H:i') }}</span>
                            @endif
                            @if ($c->start_at)
                                <span class="rounded bg-zinc-100 px-1.5 py-0.5 dark:bg-zinc-800">inicio: {{ $c->start_at->paraExibicao()->format('d/m H:i') }}</span>
                            @endif
                        </div>
                        @if ($c->total_count > 0)
                            @php $pct = fn ($n) => $c->total_count ? round($n / $c->total_count * 100) : 0; @endphp
                            <div class="mt-1.5 flex h-2 w-full max-w-sm overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800" title="{{ $c->sent_count }} enviada(s) · {{ $c->skipped_count }} pulada(s) · {{ $c->failed_count }} falhada(s) · {{ $c->pendentes_count }} pendente(s)">
                                <div class="bg-emerald-500" style="width: {{ $pct($c->sent_count) }}%"></div>
                                <div class="bg-amber-400" style="width: {{ $pct($c->skipped_count) }}%"></div>
                                <div class="bg-red-500" style="width: {{ $pct($c->failed_count) }}%"></div>
                            </div>
                            <div class="mt-0.5 text-[10px] text-zinc-400">{{ $c->sent_count }} enviada(s) · {{ $c->skipped_count }} pulada(s) · {{ $c->failed_count }} falhada(s) · {{ $c->pendentes_count }} pendente(s)</div>
                        @endif
                    </div>

                    <flux:dropdown position="bottom" align="end">
                        <button type="button" class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" aria-label="Acoes">
                            <flux:icon icon="ellipsis-vertical" variant="micro" />
                        </button>
                        <flux:menu>
                            @if (in_array($c->status, ['draft', 'previewed'], true))
                                <flux:menu.item wire:click="openPreview({{ $c->id }})" icon="eye">Preview + aprovar</flux:menu.item>
                                <flux:menu.item wire:click="edit({{ $c->id }})" icon="pencil-square">Editar</flux:menu.item>
                            @endif
                            @if ($c->total_count > 0)
                                <flux:menu.item wire:click="showTargets({{ $c->id }})" icon="list-bullet">Ver destinatarios</flux:menu.item>
                            @endif
                            @if (in_array($c->status, ['approved', 'running'], true))
                                <flux:menu.item wire:click="askPause({{ $c->id }})" icon="pause">Pausar</flux:menu.item>
                            @endif
                            @if ($c->status === 'paused')
                                <flux:menu.item wire:click="resume({{ $c->id }})" icon="play">Retomar</flux:menu.item>
                            @endif
                            @if ($c->status === 'approved')
                                <flux:menu.item wire:click="askUnapprove({{ $c->id }})" icon="arrow-uturn-left">Des-aprovar (voltar a rascunho)</flux:menu.item>
                            @endif
                            @if ($c->status !== 'cancelled')
                                <flux:menu.separator />
                                <flux:menu.item wire:click="askCancel({{ $c->id }})" icon="no-symbol" variant="danger">Cancelar campanha</flux:menu.item>
                            @endif
                        </flux:menu>
                    </flux:dropdown>
                </div>
            @empty
                <div class="flex flex-col items-center gap-2 p-10 text-center text-zinc-400">
                    <flux:icon icon="megaphone" class="size-8" />
                    <p class="text-sm">Nenhuma campanha. Crie a primeira — nada dispara sem o seu preview + aprovacao.</p>
                </div>
            @endforelse
        </div>
    </div>

    {{-- MODAL: criar/editar (draft) --}}
    @if ($showForm)
        <x-modal wireClose="closeForm" title="{{ $editingId ? 'Editar campanha' : 'Nova campanha' }}" maxWidth="xl">
            <form id="campanha-form" wire:submit="save" class="space-y-4">
                <div>
                    <label class="mb-1 block text-xs font-medium">Nome</label>
                    <input type="text" wire:model="cName" data-autofocus maxlength="120"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    @error('cName') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Mensagem</label>
                    <textarea wire:model="cMessage" rows="3" placeholder="ex.: {saudacao}, {nome}! Passando pra saber se ainda tem interesse no orcamento."
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"></textarea>
                    @error('cMessage') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    <p class="mt-1 text-[11px] text-zinc-400">Placeholders: <code>{nome}</code> <code>{saudacao}</code> <code>{data}</code> <code>{hora}</code>. <strong>{{ '{senha:...}' }} e proibido</strong> em proativa.</p>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Publico</label>
                    <div class="flex flex-wrap items-center gap-4 text-sm">
                        <label class="inline-flex items-center gap-1.5"><input type="radio" wire:model.live="cAudienceType" value="tags"> Por tag</label>
                        <label class="inline-flex items-center gap-1.5"><input type="radio" wire:model.live="cAudienceType" value="coluna_kanban"> Coluna do Kanban</label>
                        <label class="inline-flex items-center gap-1.5"><input type="radio" wire:model.live="cAudienceType" value="contatos"> Contatos especificos</label>
                    </div>
                    @error('cAudienceType') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror

                    @if ($cAudienceType === 'tags')
                        <div class="mt-2 flex flex-wrap gap-2 rounded-lg border border-zinc-200 p-2 dark:border-zinc-700">
                            @forelse ($allTags as $t)
                                <label class="inline-flex cursor-pointer items-center gap-1.5 text-sm" wire:key="camptag-{{ $t->id }}">
                                    <input type="checkbox" value="{{ $t->id }}" wire:model.live="cTagIds" class="rounded border-zinc-300 dark:border-zinc-700">
                                    <x-tag-chip :color="$t->color" small>{{ $t->name }}</x-tag-chip>
                                </label>
                            @empty
                                <p class="text-xs text-zinc-400">Nenhuma tag ainda (crie no painel de um contato).</p>
                            @endforelse
                        </div>
                    @elseif ($cAudienceType === 'coluna_kanban')
                        <select wire:model="cColumnId" class="mt-2 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                            <option value="">Escolha a coluna...</option>
                            @foreach ($columns as $col)
                                <option value="{{ $col->id }}">{{ $col->name }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-[11px] text-zinc-400">Quem esta NA coluna na hora da aprovacao (retrato).</p>
                    @else
                        <div class="mt-2 rounded-lg border border-zinc-200 dark:border-zinc-700">
                            <div class="border-b border-zinc-100 p-2 dark:border-zinc-800">
                                <input type="search" wire:model.live.debounce.250ms="cContactSearch" placeholder="Buscar nome ou numero..."
                                    class="w-full rounded-lg border border-zinc-300 bg-white py-1.5 px-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                            </div>
                            <div class="max-h-40 overflow-y-auto p-1">
                                @forelse ($contactOptions as $co)
                                    <label wire:key="campc-{{ $co->id }}" class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                        <input type="checkbox" value="{{ $co->id }}" wire:model.live="cContactIds" class="rounded border-zinc-300 dark:border-zinc-700">
                                        <span class="min-w-0 flex-1 truncate">{{ $co->push_name ?: \Illuminate\Support\Str::before($co->remote_jid, '@') }}</span>
                                        @if ($co->proactive_opt_in)
                                            <span class="shrink-0 rounded bg-emerald-100 px-1 text-[10px] text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">opt-in</span>
                                        @else
                                            <span class="shrink-0 rounded bg-zinc-100 px-1 text-[10px] text-zinc-400 dark:bg-zinc-800">sem opt-in</span>
                                        @endif
                                    </label>
                                @empty
                                    <p class="px-2 py-3 text-center text-xs text-zinc-400">Busque contatos acima.</p>
                                @endforelse
                            </div>
                        </div>
                        <p class="mt-1 text-[11px] text-zinc-400">{{ count($cContactIds) }} selecionado(s). Sem opt-in = fica de fora (o preview mostra).</p>
                    @endif
                </div>
                <div>
                    <label class="mb-1 flex items-center gap-1 text-xs font-medium">Inicio (opcional)
                        <x-info-tip text="Vazio = assim que aprovada, dentro da janela proativa. Com data/hora, a agenda comeca dali (sempre dentro da janela)." />
                    </label>
                    <input type="datetime-local" wire:model="cStartAt"
                        class="w-56 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    @error('cStartAt') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
            </form>
            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="closeForm" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                    <button type="submit" form="campanha-form" class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-zinc-900">
                        <flux:icon icon="check" variant="micro" /> Salvar rascunho
                    </button>
                </div>
            </x-slot:footer>
        </x-modal>
    @endif

    {{-- MODAL: preview (lista EXATA + excluidos + exemplo) --}}
    @if ($preview)
        <x-modal wireClose="closePreview" title="Preview — {{ $preview['campanha']->name }}" maxWidth="xl">
            <div class="space-y-3 text-sm">
                <div class="rounded-lg border border-emerald-200 bg-emerald-50/40 p-3 dark:border-emerald-900 dark:bg-emerald-950/20">
                    <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">
                        Vao receber ({{ $preview['eligiveis']->count() }})
                    </div>
                    @forelse ($preview['eligiveis'] as $e)
                        <div class="flex items-center justify-between gap-2 py-0.5" wire:key="pe-{{ $e->id }}">
                            <span class="min-w-0 truncate">{{ $e->push_name ?: 'Sem nome' }}</span>
                            <span class="shrink-0 text-xs text-zinc-400">{{ \Illuminate\Support\Str::before($e->remote_jid, '@') }}</span>
                        </div>
                    @empty
                        <p class="text-xs text-zinc-400">Ninguem elegivel (publico sem opt-in).</p>
                    @endforelse
                </div>

                @if ($preview['excluidos'] !== [])
                    <div class="rounded-lg border border-amber-200 bg-amber-50/40 p-3 dark:border-amber-900 dark:bg-amber-950/20">
                        <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300">
                            Ficam de fora ({{ count($preview['excluidos']) }})
                        </div>
                        @foreach ($preview['excluidos'] as $ex)
                            <div class="flex items-center justify-between gap-2 py-0.5" wire:key="px-{{ $ex['contact']->id }}">
                                <span class="min-w-0 truncate">{{ $ex['contact']->push_name ?: \Illuminate\Support\Str::before($ex['contact']->remote_jid, '@') }}</span>
                                <span class="shrink-0 rounded bg-amber-100 px-1.5 text-[10px] text-amber-700 dark:bg-amber-950 dark:text-amber-300">
                                    {{ ['sem_opt_in' => 'sem opt-in', 'off' => 'contato off', 'grupo' => 'grupo'][$ex['motivo']] ?? $ex['motivo'] }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($preview['exemplo'])
                    <div class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800/50">
                        <div class="mb-1 text-[10px] uppercase tracking-wide text-zinc-400">Mensagem de exemplo (1o contato)</div>
                        <div class="whitespace-pre-wrap">{{ $preview['exemplo'] }}</div>
                    </div>
                @endif

                <p class="text-[11px] text-zinc-400">
                    Retrato de AGORA — o publico so congela na aprovacao. Aprovar cria a agenda
                    (janela proativa + espacamento aleatorio) e TRAVA mensagem e publico.
                </p>
            </div>
            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="closePreview" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Fechar</button>
                    <button type="button" wire:click="askApprove({{ $preview['campanha']->id }})" @disabled($preview['eligiveis']->isEmpty())
                        class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-40">
                        <flux:icon icon="check-badge" variant="micro" /> Aprovar campanha
                    </button>
                </div>
            </x-slot:footer>
        </x-modal>
    @endif

    {{-- MODAL: confirmacao FORTE de aprovacao --}}
    @if ($approving)
        <x-modal wireClose="cancelApprove" title="Aprovar a campanha?">
            <div class="flex items-start gap-3">
                <div class="mt-0.5 text-rose-500"><flux:icon icon="megaphone" class="size-6" /></div>
                <p class="text-sm text-zinc-600 dark:text-zinc-300">
                    Aprovar <strong>"{{ $approving->name }}"</strong> CONGELA a lista e a mensagem e cria a
                    agenda de envio. Quando o disparo existir (proxima fatia) e o interruptor de proativas
                    estiver LIGADO, essas mensagens <strong>serao enviadas de verdade</strong> — dentro da
                    janela e dos tetos. Confirmar a aprovacao?
                </p>
            </div>
            <div class="flex justify-end gap-2 pt-4">
                <button type="button" wire:click="cancelApprove" data-autofocus class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                <button type="button" wire:click="approveConfirmed" class="inline-flex items-center gap-1.5 rounded-lg bg-rose-600 px-4 py-2 text-sm font-medium text-white hover:bg-rose-700">
                    <flux:icon icon="check-badge" variant="micro" /> Aprovar
                </button>
            </div>
        </x-modal>
    @endif

    {{-- MODAL: cancelar campanha --}}
    @if ($cancelling)
        <x-modal wireClose="cancelCancel" title="Cancelar campanha">
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                Cancelar <strong>"{{ $cancelling->name }}"</strong>? Os agendamentos pendentes sao
                descartados (ficam registrados como pulados). Nao da pra reativar — teria que criar outra.
            </p>
            <div class="flex justify-end gap-2 pt-4">
                <button type="button" wire:click="cancelCancel" data-autofocus class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Voltar</button>
                <button type="button" wire:click="cancelConfirmed" class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                    <flux:icon icon="no-symbol" variant="micro" /> Cancelar campanha
                </button>
            </div>
        </x-modal>
    @endif

    {{-- MODAL: des-aprovar --}}
    @if ($unapproving)
        <x-modal wireClose="cancelUnapprove" title="Desfazer aprovacao">
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                Voltar <strong>"{{ $unapproving->name }}"</strong> pra rascunho? A agenda congelada e
                apagada e a campanha volta a ser editavel. (So possivel enquanto nada foi enviado.)
            </p>
            <div class="flex justify-end gap-2 pt-4">
                <button type="button" wire:click="cancelUnapprove" data-autofocus class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Voltar</button>
                <button type="button" wire:click="unapproveConfirmed" class="inline-flex items-center gap-1.5 rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700">
                    <flux:icon icon="arrow-uturn-left" variant="micro" /> Des-aprovar
                </button>
            </div>
        </x-modal>
    @endif

    {{-- P-3 MODAL: pausar --}}
    @if ($pausing)
        <x-modal wireClose="cancelPause" title="Pausar campanha">
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                Pausar <strong>"{{ $pausing->name }}"</strong>? Os agendamentos pendentes param de ser
                processados ate voce retomar (ao retomar, horarios vencidos sao reagendados na janela).
            </p>
            <div class="flex justify-end gap-2 pt-4">
                <button type="button" wire:click="cancelPause" data-autofocus class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Voltar</button>
                <button type="button" wire:click="pauseConfirmed" class="inline-flex items-center gap-1.5 rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700">
                    <flux:icon icon="pause" variant="micro" /> Pausar
                </button>
            </div>
        </x-modal>
    @endif

    {{-- P-3 MODAL: destinatarios (status + motivo + hora) --}}
    @if ($targetsOfId)
        <x-modal wireClose="closeTargets" title="Destinatarios" maxWidth="lg">
            <ul class="divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                @forelse ($targetsDe as $t)
                    <li class="flex items-center justify-between gap-2 py-2" wire:key="tg-{{ $t->id }}">
                        <span class="min-w-0 flex-1 truncate">{{ $t->contact?->push_name ?: \Illuminate\Support\Str::before((string) $t->contact?->remote_jid, '@') }}</span>
                        <span @class([
                            'shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium',
                            'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' => in_array($t->status, ['pending', 'processing'], true),
                            'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' => $t->status === 'sent',
                            'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300' => $t->status === 'skipped',
                            'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' => $t->status === 'failed',
                        ])>{{ ['pending' => 'agendada', 'processing' => 'processando', 'sent' => 'enviada', 'skipped' => 'pulada', 'failed' => 'falhou'][$t->status] ?? $t->status }}@if ($t->skip_reason) · {{ $t->skip_reason }}@endif</span>
                        <span class="shrink-0 text-xs text-zinc-400">{{ ($t->sent_at ?? $t->scheduled_at)?->paraExibicao()->format('d/m H:i') ?? '-' }}</span>
                    </li>
                @empty
                    <li class="py-4 text-center text-xs text-zinc-400">Sem destinatarios.</li>
                @endforelse
            </ul>
        </x-modal>
    @endif
</div>
