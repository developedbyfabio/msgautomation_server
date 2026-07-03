<div class="mx-auto max-w-3xl space-y-6 p-6">
    <div>
        <h1 class="text-lg font-semibold">Perfil</h1>
        <p class="text-sm text-zinc-500">Seus dados de acesso. Ações sensíveis pedem a senha atual.</p>
    </div>

    {{-- Dados + e-mail --}}
    <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <h2 class="mb-1 text-sm font-semibold">Dados da conta</h2>
        <p class="mb-4 text-xs text-zinc-500">Logado como <span class="font-medium">{{ $user->name }}</span> — {{ $user->email }}</p>

        <form wire:submit="salvarEmail" class="space-y-3">
            <div>
                <label class="mb-1 block text-xs font-medium">Novo e-mail</label>
                <input type="email" wire:model="emailNovo" autocomplete="email"
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800">
                @error('emailNovo') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium">Senha atual (confirmação)</label>
                <input type="password" wire:model="senhaEmail" autocomplete="current-password"
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800">
                @error('senhaEmail') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                Trocar e-mail
            </button>
        </form>
    </section>

    {{-- Senha --}}
    <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <h2 class="mb-4 text-sm font-semibold">Trocar senha</h2>
        <form wire:submit="salvarSenha" class="space-y-3">
            <div>
                <label class="mb-1 block text-xs font-medium">Senha atual</label>
                <input type="password" wire:model="senhaAtual" autocomplete="current-password"
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800">
                @error('senhaAtual') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-medium">Nova senha (mín. 8, letras e números)</label>
                    <input type="password" wire:model="senhaNova" autocomplete="new-password"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800">
                    @error('senhaNova') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Confirmar nova senha</label>
                    <input type="password" wire:model="senhaNova_confirmation" autocomplete="new-password"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800">
                </div>
            </div>
            <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                Trocar senha
            </button>
        </form>
    </section>

    {{-- 2FA --}}
    <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div class="mb-1 flex items-center gap-2">
            <h2 class="text-sm font-semibold">Verificação em duas etapas (2FA)</h2>
            @if ($twofaAtivo)
                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">ATIVO</span>
            @elseif ($twofaPendente)
                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">AGUARDANDO CONFIRMAÇÃO</span>
            @else
                <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-[10px] font-semibold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">DESLIGADO</span>
            @endif
        </div>
        <p class="mb-4 text-xs text-zinc-500">Código de 6 dígitos de um app autenticador (Google Authenticator, 1Password etc.) além da senha.</p>

        @if (! $twofaAtivo && ! $twofaPendente)
            <form wire:submit="ativar2fa" class="space-y-3">
                <div>
                    <label class="mb-1 block text-xs font-medium">Senha atual (confirmação)</label>
                    <input type="password" wire:model="senha2fa" autocomplete="current-password"
                        class="w-full max-w-xs rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800">
                    @error('senha2fa') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                    Ativar 2FA
                </button>
            </form>
        @endif

        @if ($twofaPendente)
            <div class="space-y-4">
                <div class="flex flex-col items-start gap-4 sm:flex-row">
                    <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-700">
                        {!! $user->twoFactorQrCodeSvg() !!}
                    </div>
                    <div class="space-y-2 text-xs text-zinc-600 dark:text-zinc-300">
                        <p>1. Escaneie o QR no app autenticador.</p>
                        <p>2. Se não der pra escanear, chave manual:
                            <code class="rounded bg-zinc-100 px-1 py-0.5 dark:bg-zinc-800">{{ decrypt($user->two_factor_secret) }}</code></p>
                        <p>3. Digite o código de 6 dígitos abaixo pra LIGAR.</p>
                    </div>
                </div>
                <form wire:submit="confirmar2fa" class="flex items-end gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium">Código do app</label>
                        <input type="text" wire:model="codigo2fa" inputmode="numeric" autocomplete="one-time-code"
                            class="w-40 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-center text-sm tracking-widest focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800">
                    </div>
                    <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                        Confirmar e ligar
                    </button>
                </form>
                @error('codigo2fa') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                <form wire:submit="desativar2fa" class="flex items-end gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium">Cancelar ativação (senha atual)</label>
                        <input type="password" wire:model="senha2fa"
                            class="w-40 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800">
                    </div>
                    <button type="submit" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800">
                        Cancelar
                    </button>
                </form>
            </div>
        @endif

        @if ($twofaAtivo)
            <div class="space-y-4">
                @if (count($recoveryCodes))
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/40">
                        <p class="mb-2 text-xs font-semibold text-amber-800 dark:text-amber-300">
                            Códigos de recuperação — guarde AGORA (cada um vale uma vez, se você perder o app):
                        </p>
                        <div class="grid grid-cols-2 gap-1 font-mono text-xs">
                            @foreach ($recoveryCodes as $rc)
                                <span class="rounded bg-white px-2 py-1 dark:bg-zinc-900">{{ $rc }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="grid gap-4 sm:grid-cols-2">
                    <form wire:submit="regenerarCodigos" class="space-y-2">
                        <label class="block text-xs font-medium">Regenerar códigos de recuperação (senha atual)</label>
                        <input type="password" wire:model="senha2fa" autocomplete="current-password"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800">
                        @error('senha2fa') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                        <button type="submit" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800">
                            Regenerar códigos
                        </button>
                    </form>
                    <form wire:submit="desativar2fa" class="space-y-2">
                        <label class="block text-xs font-medium">Desativar 2FA (senha atual)</label>
                        <input type="password" wire:model="senha2fa" autocomplete="current-password"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800">
                        <button type="submit" class="rounded-lg border border-red-300 px-4 py-2 text-sm text-red-600 hover:bg-red-50 dark:border-red-900 dark:hover:bg-red-950/40">
                            Desativar 2FA
                        </button>
                    </form>
                </div>
            </div>
        @endif
    </section>
</div>
