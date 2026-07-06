<div class="h-full overflow-y-auto">
    <div class="mx-auto max-w-3xl p-6 space-y-4">
        <h1 class="text-xl font-semibold">Assinatura</h1>

        @if (in_array($conta->subscription_status, ['suspended', 'canceled'], true))
            <div class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/50 dark:text-red-300">
                <flux:icon icon="exclamation-triangle" variant="micro" class="inline size-3.5" />
                {{ $conta->subscription_status === 'canceled' ? 'Assinatura cancelada.' : 'Conta suspensa por pendencia de pagamento.' }}
                O atendimento automatico esta <strong>pausado</strong> e o painel bloqueado —
                <strong>nenhum dado foi apagado</strong>: assim que o pagamento for confirmado, tudo volta sozinho.
            </div>
        @elseif ($conta->subscription_status === 'overdue')
            <div class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-950/50 dark:text-amber-300">
                <flux:icon icon="clock" variant="micro" class="inline size-3.5" />
                Ha um pagamento pendente. Regularize para nao ter o atendimento pausado.
            </div>
        @endif

        <div class="grid gap-4 md:grid-cols-[1fr_260px]">
            <div class="space-y-4 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-zinc-500">Status da assinatura</p>
                    <span class="rounded px-2 py-0.5 text-xs font-medium
                        {{ match ($conta->subscription_status) {
                            'active' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
                            'trial' => 'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300',
                            'overdue' => 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
                            default => 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
                        } }}">{{ $statusLabel }}</span>
                </div>

                @if ($diasRestantes !== null)
                    <p class="text-sm text-zinc-600 dark:text-zinc-300">
                        Seu teste gratis termina em <strong>{{ $diasRestantes }}</strong> dia(s)
                        ({{ $conta->trial_ends_at->paraExibicao()->format('d/m/Y') }}).
                        Assine agora e a cobranca so comeca quando o teste acabar.
                    </p>
                @endif

                @if ($conta->document === null)
                    <p class="text-sm text-zinc-500">Conta interna (sem CPF/CNPJ) — cobranca nao se aplica.</p>
                @else
                    <div class="flex flex-wrap gap-2">
                        @if ($conta->asaas_subscription_id === null || $conta->subscription_status === 'canceled')
                            <button type="button" wire:click="assinar" wire:loading.attr="disabled" wire:target="assinar"
                                class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-60">
                                <flux:icon icon="arrow-path" variant="micro" class="animate-spin" wire:loading wire:target="assinar" />
                                <flux:icon icon="credit-card" variant="micro" wire:loading.remove wire:target="assinar" />
                                Assinar agora
                            </button>
                        @else
                            @if ($conta->subscription_status !== 'active')
                                <button type="button" wire:click="abrirFatura" wire:loading.attr="disabled" wire:target="abrirFatura"
                                    class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-60">
                                    <flux:icon icon="arrow-path" variant="micro" class="animate-spin" wire:loading wire:target="abrirFatura" />
                                    Pagar agora
                                </button>
                            @else
                                <button type="button" wire:click="abrirFatura"
                                    class="inline-flex items-center gap-2 rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">
                                    Ver fatura em aberto
                                </button>
                            @endif
                            <button type="button" wire:click="pedirCancelamento"
                                class="inline-flex items-center gap-2 rounded-lg border border-red-300 px-4 py-2 text-sm text-red-600 hover:bg-red-50 dark:border-red-900 dark:hover:bg-red-950">
                                Cancelar assinatura
                            </button>
                        @endif
                    </div>

                    {{-- PCI: o pagamento e HOSPEDADO no Asaas — cartao nunca passa aqui. --}}
                    <p class="text-xs text-zinc-400">
                        O pagamento acontece no ambiente seguro do <strong>Asaas</strong> (cartao, Pix ou boleto).
                        Nenhum dado de cartao passa pelo nosso sistema.
                    </p>
                @endif
            </div>

            <aside class="h-fit rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                <p class="text-xs font-medium uppercase tracking-wide text-zinc-400">Seu plano</p>
                <p class="mt-1 font-semibold">{{ $plano['name'] }}</p>
                <p class="mt-1 text-2xl font-bold">R$ {{ $plano['price_monthly'] }}<span class="text-sm font-normal text-zinc-500">/mes</span></p>
                <ul class="mt-3 space-y-2 text-sm text-zinc-600 dark:text-zinc-300">
                    @foreach ($plano['features'] as $item)
                        <li class="flex items-start gap-2">
                            <flux:icon icon="check" variant="micro" class="mt-0.5 shrink-0 text-emerald-600" /> {{ $item }}
                        </li>
                    @endforeach
                </ul>
            </aside>
        </div>
    </div>

    @if ($confirmandoCancelamento)
        <x-modal wireClose="fecharCancelamento" title="Cancelar assinatura">
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                Cancelar a assinatura <strong>pausa o atendimento automatico</strong> e bloqueia o painel
                (menos esta tela). <strong>Nenhum dado e apagado</strong> — assinando de novo, tudo volta.
            </p>
            <div class="flex justify-end gap-2 pt-4">
                <button type="button" wire:click="fecharCancelamento" data-autofocus class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Voltar</button>
                <button type="button" wire:click="cancelar" class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                    Cancelar assinatura
                </button>
            </div>
        </x-modal>
    @endif
</div>
