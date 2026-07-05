{{-- Fatia 2/4b/9 — toggle do Modo de Operacao (server-side, por conta), agora como
     SWITCH visual (Tailwind puro — flux:switch e Pro). Ligar abre o modal de
     ativacao com SELECT obrigatorio de fluxo habilitado; desligar e imediato. --}}
<div class="contents">
    <button type="button" wire:click="toggle" wire:loading.attr="disabled" wire:target="toggle"
        role="switch" aria-checked="{{ $auto ? 'true' : 'false' }}"
        title="Modo de operacao da conta (atual: {{ $this->label() }})"
        aria-label="Alternar modo de operacao (atual: {{ $this->label() }})"
        class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-800 disabled:opacity-60 dark:hover:bg-zinc-800 dark:hover:text-zinc-200">
        <span @class([
            'relative inline-flex h-4 w-7 shrink-0 items-center rounded-full transition-colors',
            'bg-emerald-500' => $auto,
            'bg-zinc-300 dark:bg-zinc-600' => ! $auto,
        ])>
            <span @class([
                'inline-block size-3 rounded-full bg-white shadow transition-transform',
                'translate-x-3.5' => $auto,
                'translate-x-0.5' => ! $auto,
            ])></span>
        </span>
        <span wire:loading.remove wire:target="toggle">{{ $this->label() }}</span>
        <span class="inline-flex items-center gap-1" wire:loading wire:target="toggle">
            <flux:icon icon="arrow-path" variant="micro" class="animate-spin" /> {{ $this->label() }}
        </span>
    </button>

    {{-- Confirmacao ao LIGAR (mesmo padrao do kill switch: x-modal + flags). --}}
    @if ($confirming)
        <x-modal wireClose="cancelarAtivacao" title="Ativar o Modo Automatico?">
            @if ($fluxosHabilitados->isNotEmpty())
                <p class="text-sm text-zinc-600 dark:text-zinc-300">
                    Ao ativar, <strong>toda mensagem recebida</strong> — exceto grupos e contatos
                    silenciados — sera respondida automaticamente pelo fluxo selecionado.
                </p>
                <div class="pt-3">
                    <label for="fluxo-padrao-ativacao" class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Fluxo de atendimento
                    </label>
                    <select id="fluxo-padrao-ativacao" wire:model.live="fluxoEscolhido"
                        class="w-full rounded-lg border-zinc-300 bg-white text-sm dark:border-zinc-700 dark:bg-zinc-800">
                        <option value="">Escolha um fluxo...</option>
                        @foreach ($fluxosHabilitados as $f)
                            <option value="{{ $f->id }}">{{ $f->name }}</option>
                        @endforeach
                    </select>
                    @error('fluxoEscolhido')
                        <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>
                <div class="flex justify-end gap-2 pt-4">
                    <button type="button" wire:click="cancelarAtivacao" data-autofocus
                        class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                    <button type="button" wire:click="confirmarAtivacao" @disabled(! $fluxoEscolhido)
                        class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50">
                        <flux:icon icon="bolt" variant="micro" /> Ativar
                    </button>
                </div>
            @else
                <p class="text-sm text-zinc-600 dark:text-zinc-300">
                    Sua conta <strong>nao tem nenhum fluxo habilitado</strong>. Sem um fluxo, o Modo
                    Automatico <strong>nao respondera nada</strong>. Voce pode ativar mesmo assim e
                    criar/ligar um fluxo em
                    <a href="{{ route('fluxos') }}" wire:navigate class="font-medium underline">Fluxos</a>. Continuar?
                </p>
                <div class="flex justify-end gap-2 pt-4">
                    <button type="button" wire:click="cancelarAtivacao" data-autofocus
                        class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                    <button type="button" wire:click="confirmarAtivacao"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                        <flux:icon icon="bolt" variant="micro" /> Ativar mesmo assim
                    </button>
                </div>
            @endif
        </x-modal>
    @endif
</div>
