<div class="h-full overflow-y-auto">
    <div class="mx-auto max-w-4xl p-6 space-y-4">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <h1 class="text-xl font-semibold">Servidores</h1>
                <x-info-tip text="Inventario dos servidores monitorados. Cada servidor tem um token de agente (guardado no Cofre) que autentica o envio de metricas. Alertas e painel ao vivo chegam nas proximas fatias." />
            </div>
            <button type="button" wire:click="novo"
                class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-3 py-2 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
                <flux:icon icon="plus" variant="micro" /> Novo servidor
            </button>
        </div>

        <div class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/50 dark:text-amber-300">
            <flux:icon icon="shield-check" variant="micro" class="inline size-3.5" />
            O agente coletor faz <strong>PUSH de saida</strong> para este sistema (nenhuma porta abre no servidor
            monitorado). O token e exibido <strong>uma unica vez</strong> ao criar/regenerar e fica guardado
            <strong>cifrado no Cofre de credenciais</strong> (nome <code>agente-servidor-&lt;id&gt;</code>).
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white divide-y divide-zinc-100 dark:border-zinc-800 dark:bg-zinc-900 dark:divide-zinc-800">
            @forelse ($servers as $s)
                @php [$seloLabel, $seloCor] = $this->selo($s->last_seen_at); @endphp
                <div class="flex items-center gap-3 p-3" wire:key="srv-{{ $s->id }}">
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-zinc-500 dark:bg-zinc-800">
                        <flux:icon icon="server" variant="micro" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="truncate font-medium">{{ $s->name }}</span>
                            <span class="rounded bg-zinc-100 px-1.5 text-[10px] uppercase text-zinc-500 dark:bg-zinc-800">{{ $s->os }}</span>
                            @if ($s->grupo)
                                <span class="rounded bg-zinc-100 px-1.5 text-[10px] text-zinc-500 dark:bg-zinc-800">{{ $s->grupo }}</span>
                            @endif
                            @unless ($s->enabled)
                                <span class="inline-flex items-center rounded-full bg-zinc-200 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">Desativado</span>
                            @endunless
                        </div>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-zinc-400">
                            @if ($s->host)
                                <span class="font-mono">{{ $s->host }}</span>
                                <span aria-hidden="true">&middot;</span>
                            @endif
                            @if ($s->last_seen_at)
                                <span>visto {{ $s->last_seen_at->paraExibicao()->format('d/m H:i:s') }}</span>
                            @endif
                        </div>
                    </div>

                    {{-- Selo informativo (so last_seen_at — sem logica de alerta, que e S2). --}}
                    <span @class([
                        'inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
                        'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' => $seloCor === 'emerald',
                        'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300' => $seloCor === 'amber',
                        'bg-zinc-200 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' => $seloCor === 'zinc',
                    ])>
                        <span @class([
                            'size-1.5 rounded-full',
                            'bg-emerald-500' => $seloCor === 'emerald',
                            'bg-amber-500' => $seloCor === 'amber',
                            'bg-zinc-400' => $seloCor === 'zinc',
                        ])></span>
                        {{ $seloLabel }}
                    </span>

                    <flux:dropdown position="bottom" align="end">
                        <button type="button" class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" aria-label="Acoes">
                            <flux:icon icon="ellipsis-vertical" variant="micro" />
                        </button>
                        <flux:menu>
                            <flux:menu.item wire:click="edit({{ $s->id }})" icon="pencil-square">Editar</flux:menu.item>
                            <flux:menu.item wire:click="askRegenerate({{ $s->id }})" icon="arrow-path">Regenerar token</flux:menu.item>
                            <flux:menu.item wire:click="toggleEnabled({{ $s->id }})" icon="{{ $s->enabled ? 'pause' : 'play' }}">
                                {{ $s->enabled ? 'Desativar ingestao' : 'Reativar ingestao' }}
                            </flux:menu.item>
                            <flux:menu.separator />
                            <flux:menu.item wire:click="confirmDelete({{ $s->id }})" icon="trash" variant="danger">Excluir</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>
            @empty
                <div class="flex flex-col items-center gap-2 p-10 text-center text-zinc-400">
                    <flux:icon icon="server-stack" class="size-8" />
                    <p class="text-sm">Nenhum servidor cadastrado. Cadastre o primeiro para gerar o token do agente.</p>
                </div>
            @endforelse
        </div>
    </div>

    {{-- MODAL: criar/editar --}}
    @if ($showForm)
        <x-modal wireClose="closeForm" title="{{ $editingId ? 'Editar servidor' : 'Novo servidor' }}">
            <form id="server-form" wire:submit="save" class="space-y-3">
                <div>
                    <label class="mb-1 block text-xs font-medium">Nome</label>
                    <input type="text" wire:model="name" placeholder="ex.: Servidor ERP" data-autofocus
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    @error('name') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Host / IP (opcional, descritivo)</label>
                    <input type="text" wire:model="host" placeholder="ex.: 192.168.11.20 ou erp.empresa.local"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    @error('host') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium">Sistema operacional</label>
                        <select wire:model="os"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                            <option value="linux">Linux</option>
                        </select>
                        @error('os') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                        <p class="mt-1 text-[11px] text-zinc-400">Windows entra numa fatia futura.</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium">Grupo (opcional)</label>
                        <input type="text" wire:model="grupo" placeholder="ex.: producao"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                        @error('grupo') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                </div>
                @unless ($editingId)
                    <p class="text-[11px] text-zinc-400">Ao salvar, o <strong>token do agente</strong> sera gerado e exibido uma unica vez.</p>
                @endunless
            </form>

            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="closeForm" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                    <button type="submit" form="server-form" class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-zinc-900">
                        <flux:icon icon="check" variant="micro" /> Salvar
                    </button>
                </div>
            </x-slot:footer>
        </x-modal>
    @endif

    {{-- MODAL: token exibido UMA vez (criacao/regeneracao) --}}
    @if ($plainToken)
        <x-modal wireClose="dismissToken" title="Token do agente — {{ $plainTokenFor }}" maxWidth="lg">
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                Copie o token abaixo para configurar o coletor. Ele <strong>nao sera mostrado de novo</strong> —
                depois, so revelando no <a href="{{ route('senhas') }}" wire:navigate class="font-medium underline">Cofre de credenciais</a>
                (com re-senha de login) ou regenerando aqui.
            </p>
            <div class="mt-3 rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-800">
                <code class="select-all break-all font-mono text-sm">{{ $plainToken }}</code>
            </div>
            <p class="mt-2 text-[11px] text-zinc-400">
                O agente envia o token no header <code>X-Agent-Token</code> do POST para
                <code>{{ route('webhook.servers.ingest') }}</code>.
            </p>
            <x-slot:footer>
                <div class="flex justify-end">
                    <button type="button" wire:click="dismissToken" data-autofocus
                        class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-zinc-900">
                        <flux:icon icon="check" variant="micro" /> Copiei, fechar
                    </button>
                </div>
            </x-slot:footer>
        </x-modal>
    @endif

    {{-- MODAL: confirmar regeneracao --}}
    @if ($regenerating)
        <x-modal wireClose="cancelRegenerate" title="Regenerar token">
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                Regenerar o token de <strong>"{{ $regenerating->name }}"</strong>? O token atual
                <strong>para de valer imediatamente</strong> — o coletor instalado com ele passa a receber 401
                ate ser reconfigurado com o novo.
            </p>
            <div class="flex justify-end gap-2 pt-4">
                <button type="button" wire:click="cancelRegenerate" data-autofocus class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                <button type="button" wire:click="regenerateConfirmed" class="inline-flex items-center gap-1.5 rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700">
                    <flux:icon icon="arrow-path" variant="micro" /> Regenerar
                </button>
            </div>
        </x-modal>
    @endif

    {{-- MODAL: confirmar exclusao --}}
    @if ($deleting)
        <x-modal wireClose="cancelDelete" title="Excluir servidor">
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                Excluir <strong>"{{ $deleting->name }}"</strong>? O token do agente sai do Cofre e a ingestao
                deste servidor passa a receber 401. O coletor instalado nele (se houver) deve ser removido manualmente.
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
