<div class="flex h-full items-center justify-center p-6" wire:poll.5s="poll">
    <div class="w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-8 text-center shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div class="mx-auto mb-4 flex size-12 items-center justify-center rounded-2xl bg-emerald-600 text-white">
            <flux:icon icon="qr-code" />
        </div>

        <h1 class="text-lg font-semibold">Conectar o WhatsApp</h1>

        @if (! $temCanal)
            {{-- Prompt 23 — conta ainda SEM canal: self-service pra criar a instancia da conta. --}}
            <p class="mt-2 text-sm text-zinc-500">
                Sua conta ainda nao tem um WhatsApp conectado. Clique abaixo para criar a instancia
                e gerar o QR de conexao.
            </p>
            @if ($provisionError)
                <div class="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-600 dark:bg-red-950 dark:text-red-400">{{ $provisionError }}</div>
            @endif
            <button type="button" wire:click="conectar" wire:loading.attr="disabled" wire:target="conectar"
                class="mt-6 inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-60">
                <flux:icon icon="qr-code" variant="micro" wire:loading.remove wire:target="conectar" />
                <flux:icon icon="arrow-path" variant="micro" class="animate-spin" wire:loading wire:target="conectar" />
                <span wire:loading.remove wire:target="conectar">Conectar WhatsApp</span>
                <span wire:loading wire:target="conectar">Criando instancia...</span>
            </button>
        @else
        @php
            [$cor, $rotulo] = match ($state) {
                'connecting' => ['text-amber-600', 'conectando...'],
                'verificando' => ['text-zinc-400', 'verificando...'],
                'open' => ['text-emerald-600', 'conectado'],
                default => ['text-red-500', 'desconectado'],
            };
        @endphp
        <p class="mt-1 text-sm {{ $cor }}">Estado: {{ $rotulo }}</p>

        <div class="mt-6 flex flex-col items-center gap-4">
            @if ($qrError)
                <div class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-600 dark:bg-red-950 dark:text-red-400">
                    {{ $qrError }}
                </div>
            @elseif ($qr)
                <img src="{{ $qr }}" alt="QR code do WhatsApp" class="size-64 rounded-xl bg-white p-2 ring-1 ring-zinc-200 dark:ring-zinc-700">
                <ol class="space-y-1 text-left text-xs text-zinc-500">
                    <li>1. Abra o WhatsApp no celular.</li>
                    <li>2. Aparelhos conectados &rarr; Conectar um aparelho.</li>
                    <li>3. Aponte a camera para este QR.</li>
                </ol>
            @else
                <div class="flex size-64 items-center justify-center rounded-xl bg-zinc-100 dark:bg-zinc-800">
                    <flux:icon icon="arrow-path" class="size-6 animate-spin text-zinc-400" />
                </div>
                <p class="text-xs text-zinc-500">Carregando QR...</p>
            @endif

            <p class="text-[11px] text-zinc-400">
                O QR expira rapido. Esta tela atualiza sozinha e segue pras conversas assim que conectar.
            </p>

            <button type="button" wire:click="gerarQr"
                class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 px-4 py-2 text-sm hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">
                <flux:icon icon="arrow-path" variant="micro" wire:loading.remove wire:target="gerarQr" />
                <flux:icon icon="arrow-path" variant="micro" class="animate-spin" wire:loading wire:target="gerarQr" />
                Gerar novo QR
            </button>
        </div>
        @endif

        {{-- Prompt 24b — alternativa: canal oficial (Meta Cloud API) por credenciais. --}}
        <div class="mt-6 border-t border-zinc-100 pt-4 dark:border-zinc-800">
            <button type="button" wire:click="abrirCloud"
                class="inline-flex items-center gap-1.5 text-sm text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200">
                <flux:icon icon="cloud" variant="micro" /> Conectar via API oficial (Cloud)
            </button>
        </div>
    </div>

    {{-- MODAL: credenciais Cloud API --}}
    @if ($showCloud)
        <x-modal wireClose="fecharCloud" title="WhatsApp Cloud API (oficial)" maxWidth="lg">
            @if ($cloudSalvo)
                {{-- Pos-sucesso: configure na Meta. Segredos NUNCA em texto. --}}
                <div class="space-y-3 text-sm">
                    <div class="rounded-lg bg-emerald-50 px-3 py-2 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                        Credenciais salvas (cifradas). Access token: <strong>{{ $cloudTokenMasked }}</strong>.
                    </div>
                    <p class="text-zinc-500">No painel da Meta (WhatsApp &rarr; Configuration), configure o webhook:</p>
                    <div>
                        <label class="block text-xs font-medium mb-1">Callback URL</label>
                        <input type="text" readonly value="{{ $cloudCallbackUrl }}"
                            class="w-full rounded-lg border border-zinc-300 bg-zinc-50 px-3 py-2 text-xs dark:border-zinc-700 dark:bg-zinc-800">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Verify token</label>
                        <input type="text" readonly value="{{ $cloudVerifyShown }}"
                            class="w-full rounded-lg border border-zinc-300 bg-zinc-50 px-3 py-2 text-xs dark:border-zinc-700 dark:bg-zinc-800">
                    </div>
                    <p class="text-[11px] text-zinc-400">Assine o campo <strong>messages</strong>. Guarde o verify token: ele nao e exibido de novo.</p>
                    <div class="flex justify-end pt-1">
                        <button type="button" wire:click="fecharCloud" class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-zinc-900">Fechar</button>
                    </div>
                </div>
            @else
                <div class="space-y-3">
                    @if ($cloudError)
                        <div class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600 dark:bg-red-950 dark:text-red-400">{{ $cloudError }}</div>
                    @endif
                    @if ($cloudWarning)
                        <div class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-700 dark:bg-amber-950 dark:text-amber-300">{{ $cloudWarning }}</div>
                    @endif
                    <div>
                        <label class="block text-xs font-medium mb-1">phone_number_id <span class="text-zinc-400">(ID numerico do numero na Meta)</span></label>
                        <input type="text" wire:model="cloudPhone" data-autofocus placeholder="so digitos"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">waba_id <span class="text-zinc-400">(WhatsApp Business Account id)</span></label>
                        <input type="text" wire:model="cloudWaba"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">access_token <span class="text-zinc-400">(EAA…; secreto)</span></label>
                        <input type="password" wire:model="cloudAccessToken" autocomplete="off"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">app_secret <span class="text-zinc-400">(App settings &gt; Basic; secreto)</span></label>
                        <input type="password" wire:model="cloudAppSecret" autocomplete="off"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">verify_token <span class="text-zinc-400">(curto, voce inventa; vazio = gero um)</span></label>
                        <input type="password" wire:model="cloudVerify" autocomplete="off"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    </div>
                    <div class="flex justify-end gap-2 pt-1">
                        <button type="button" wire:click="fecharCloud" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">Cancelar</button>
                        <button type="button" wire:click="salvarCloud" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                            <flux:icon icon="check" variant="micro" /> Salvar credenciais
                        </button>
                    </div>
                </div>
            @endif
        </x-modal>
    @endif
</div>
