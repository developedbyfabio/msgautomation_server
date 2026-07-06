<div class="w-full max-w-3xl px-4 sm:px-6"
    x-data="{
        async buscaCep(v) {
            const cep = (v || '').replace(/\D/g, '');
            if (cep.length !== 8) return;
            try {
                {{-- Conveniencia client-side (ViaCEP). Fora do ar / offline: os
                     campos seguem editaveis (fallback manual) e o submit
                     revalida TUDO server-side. --}}
                const r = await fetch(`https://viacep.com.br/ws/${cep}/json/`);
                if (!r.ok) return;
                const d = await r.json();
                if (d.erro) return;
                await $wire.preencherEndereco({ logradouro: d.logradouro, bairro: d.bairro, localidade: d.localidade, uf: d.uf });
            } catch (e) { /* segue manual */ }
        }
    }">
    <div class="mb-6 text-center">
        <div class="mx-auto mb-3 flex size-12 items-center justify-center rounded-2xl bg-emerald-600 text-white">
            <flux:icon icon="chat-bubble-left-right" />
        </div>
        <h1 class="text-lg font-semibold text-white drop-shadow-sm">Crie sua conta</h1>
        <p class="text-sm text-zinc-200 drop-shadow-sm">Teste gratis por {{ $trialDias }} dias. Sem cartao de credito.</p>
    </div>

    <div class="grid gap-4 md:grid-cols-[1fr_260px]">
        <form wire:submit="cadastrar" class="space-y-4 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            {{-- tipo de pessoa --}}
            <div class="grid grid-cols-2 gap-2">
                @foreach (['pf' => 'Pessoa Fisica', 'pj' => 'Empresa (CNPJ)'] as $valor => $rotulo)
                    <label class="flex cursor-pointer items-center justify-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium
                        {{ $tipo === $valor ? 'border-emerald-600 bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' : 'border-zinc-300 text-zinc-600 dark:border-zinc-700 dark:text-zinc-300' }}">
                        <input type="radio" wire:model.live="tipo" value="{{ $valor }}" class="sr-only"> {{ $rotulo }}
                    </label>
                @endforeach
            </div>

            @if ($tipo === 'pj')
                <div>
                    <label class="mb-1 block text-xs font-medium">Razao social</label>
                    <input type="text" wire:model="razaoSocial"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800">
                    @error('razaoSocial') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium">Nome fantasia (opcional)</label>
                        <input type="text" wire:model="nomeFantasia"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium">CNPJ</label>
                        <input type="text" wire:model="documento" inputmode="numeric" placeholder="00.000.000/0000-00"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800">
                        @error('documento') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                </div>
            @endif

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="{{ $tipo === 'pf' ? '' : 'sm:col-span-2' }}">
                    <label class="mb-1 block text-xs font-medium">{{ $tipo === 'pj' ? 'Nome do responsavel' : 'Nome completo' }}</label>
                    <input type="text" wire:model="nome" autocomplete="name"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800">
                    @error('nome') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                @if ($tipo === 'pf')
                    <div>
                        <label class="mb-1 block text-xs font-medium">CPF</label>
                        <input type="text" wire:model="documento" inputmode="numeric" placeholder="000.000.000-00"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800">
                        @error('documento') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                @endif
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-medium">E-mail</label>
                    <input type="email" wire:model="email" autocomplete="email"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800">
                    @error('email') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Telefone / WhatsApp</label>
                    <input type="text" wire:model="telefone" inputmode="numeric" placeholder="(00) 00000-0000" autocomplete="tel"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800">
                    @error('telefone') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
            </div>

            {{-- endereco --}}
            <div class="grid gap-3 sm:grid-cols-3">
                <div>
                    <label class="mb-1 block text-xs font-medium">CEP</label>
                    <input type="text" wire:model="cep" inputmode="numeric" placeholder="00000-000"
                        x-on:blur="buscaCep($event.target.value)" autocomplete="postal-code"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800">
                    @error('cep') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-xs font-medium">Endereco</label>
                    <input type="text" wire:model="endereco" autocomplete="address-line1"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800">
                    @error('endereco') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="grid gap-3 sm:grid-cols-4">
                <div>
                    <label class="mb-1 block text-xs font-medium">Numero</label>
                    <input type="text" wire:model="numero"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800">
                    @error('numero') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Complemento</label>
                    <input type="text" wire:model="complemento"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800">
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-xs font-medium">Bairro</label>
                    <input type="text" wire:model="bairro"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800">
                    @error('bairro') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-xs font-medium">Cidade</label>
                    <input type="text" wire:model="cidade" autocomplete="address-level2"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800">
                    @error('cidade') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">UF</label>
                    <select wire:model="uf"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800">
                        <option value="">—</option>
                        @foreach ($ufs as $sigla) <option value="{{ $sigla }}">{{ $sigla }}</option> @endforeach
                    </select>
                    @error('uf') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
            </div>

            {{-- acesso --}}
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-medium">Senha (minimo 10 caracteres)</label>
                    <input type="password" wire:model="password" autocomplete="new-password"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800">
                    @error('password') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Confirme a senha</label>
                    <input type="password" wire:model="password_confirmation" autocomplete="new-password"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800">
                </div>
            </div>

            <label class="flex items-start gap-2 text-sm text-zinc-600 dark:text-zinc-300">
                <input type="checkbox" wire:model="aceite" class="mt-0.5 rounded border-zinc-300 dark:border-zinc-700">
                <span>Li e aceito os Termos de Uso e a Politica de Privacidade.</span>
            </label>
            @error('aceite') <p class="-mt-2 text-xs text-red-500">{{ $message }}</p> @enderror

            <button type="submit" wire:loading.attr="disabled" wire:target="cadastrar"
                class="flex w-full items-center justify-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-60">
                <flux:icon icon="arrow-path" variant="micro" class="animate-spin" wire:loading wire:target="cadastrar" />
                Criar conta e comecar o teste gratis
            </button>

            <p class="text-center text-xs text-zinc-500 dark:text-zinc-400">
                Ja tem conta? <a href="{{ route('login') }}" class="font-medium text-emerald-600 hover:underline dark:text-emerald-400">Entrar</a>
            </p>
        </form>

        {{-- plano unico (exibicao; preco vem de config/billing — ponto unico) --}}
        <aside class="h-fit rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-xs font-medium uppercase tracking-wide text-zinc-400">Seu plano</p>
            <p class="mt-1 font-semibold">{{ $plano['name'] }}</p>
            <p class="mt-1 text-2xl font-bold">R$ {{ $plano['price_monthly'] }}<span class="text-sm font-normal text-zinc-500">/mes</span></p>
            <p class="mt-1 text-xs text-emerald-600 dark:text-emerald-400">Primeiros {{ $trialDias }} dias gratis.</p>
            <ul class="mt-3 space-y-2 text-sm text-zinc-600 dark:text-zinc-300">
                @foreach ($plano['features'] as $item)
                    <li class="flex items-start gap-2">
                        <flux:icon icon="check" variant="micro" class="mt-0.5 shrink-0 text-emerald-600" /> {{ $item }}
                    </li>
                @endforeach
            </ul>
            <p class="mt-4 text-xs text-zinc-400">A cobranca so comeca depois do periodo de teste. Nenhum cartao e pedido agora.</p>
        </aside>
    </div>
</div>
