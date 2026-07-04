{{-- Fatia 2/4b — toggle do Modo de Operacao (server-side, por conta). Ligar exige
     confirmacao (o modo muda o comportamento de verdade desde a Fatia 4); desligar
     e imediato. Copy dinamica: variante de AVISO sem fluxo padrao valido. --}}
<div class="contents">
    <button type="button" wire:click="toggle" wire:loading.attr="disabled" wire:target="toggle"
        title="Modo de operacao da conta (atual: {{ $this->label() }})"
        aria-label="Alternar modo de operacao (atual: {{ $this->label() }})"
        class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-800 disabled:opacity-60 dark:hover:bg-zinc-800 dark:hover:text-zinc-200">
        <flux:icon :icon="$auto ? 'bolt' : 'user'" variant="micro" wire:loading.remove wire:target="toggle" />
        <flux:icon icon="arrow-path" variant="micro" class="animate-spin" wire:loading wire:target="toggle" />
        <span>{{ $this->label() }}</span>
    </button>

    {{-- Confirmacao ao LIGAR (mesmo padrao do kill switch: x-modal + flags). --}}
    @if ($confirming)
        <x-modal wireClose="cancelarAtivacao" title="Ativar o Modo Automatico?">
            @if ($temFluxoValido)
                <p class="text-sm text-zinc-600 dark:text-zinc-300">
                    Ao ativar o Modo Automatico, <strong>toda mensagem recebida</strong> (exceto grupos e
                    contatos silenciados) sera respondida automaticamente pelo fluxo de atendimento
                    padrao. Deseja continuar?
                </p>
            @else
                <p class="text-sm text-zinc-600 dark:text-zinc-300">
                    <strong>Nenhum fluxo de atendimento padrao</strong> esta selecionado (ou o escolhido
                    esta desabilitado). Enquanto nao houver um fluxo valido, o Modo Automatico
                    <strong>nao respondera nada</strong>. Voce pode ativar mesmo assim e escolher um
                    fluxo em <a href="{{ route('configuracoes') }}" wire:navigate class="font-medium underline">Configuracoes</a>. Continuar?
                </p>
            @endif
            <div class="flex justify-end gap-2 pt-4">
                <button type="button" wire:click="cancelarAtivacao" data-autofocus
                    class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                <button type="button" wire:click="confirmarAtivacao"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                    <flux:icon icon="bolt" variant="micro" /> Ativar
                </button>
            </div>
        </x-modal>
    @endif
</div>
