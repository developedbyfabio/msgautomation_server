@php
    $robo = \App\Models\AutoReplySetting::query()->value('enabled');
    // Fatia 3: contador de pendencias da IA (escopado pela conta-ancora, como as telas).
    $navAccountId = app(\App\Tenancy\AccountContext::class)->id(); // MT-0: conta do contexto
    $expDias = (int) config('ai.approval_expire_days', 7);
    $pendencias = \App\Models\PendingApproval::query()
        ->where('account_id', $navAccountId)
        ->where('status', 'pending')
        ->when($expDias > 0, fn ($q) => $q->where('created_at', '>=', now()->subDays($expDias)))
        ->count();
    // K-2: contador de cards em "Novo" (conversas aguardando primeira acao).
    $kanbanNovo = \App\Models\Card::query()
        ->whereHas('column', fn ($q) => $q->where('slug', 'novo'))
        ->count();
    // Fatia 23 — navegacao REAGRUPADA em linguagem de negocio (rotulos de UI;
    // rotas/URLs identicas — so a arvore do menu mudou). "Senhas" NAO renomeado
    // aqui (Fatia 24). Grupo 'Automacao' junta a engenharia do atendimento.
    $navGrupos = [
        ['heading' => null, 'items' => [
            ['painel', 'Inicio', 'chart-bar', 0],
            ['conversas', 'Atendimento', 'chat-bubble-left-right', 0],
            ['kanban', 'Kanban', 'view-columns', $kanbanNovo],
            ['contatos', 'Clientes', 'users', 0],
            ['campanhas', 'Campanhas', 'megaphone', 0],
        ]],
        ['heading' => 'Automacao', 'items' => [
            ['regras', 'Respostas automaticas', 'bolt', 0],
            ['fluxos', 'Menus de atendimento', 'rectangle-stack', 0],
            ['conhecimento', 'Informacoes do negocio', 'book-open', 0],
            ['variaveis', 'Variaveis', 'variable', 0],
            ['revisao', 'Sugestoes da IA', 'inbox', $pendencias],
        ]],
        ['heading' => null, 'items' => [
            ['senhas', 'Cofre de credenciais', 'key', 0], // Fatia 24: rotulo; rota/chave 'senhas' intocadas
            ['logs', 'Logs', 'document-text', 0],
            ['configuracoes', 'Configuracoes', 'cog-6-tooth', 0],
            ['perfil', 'Perfil', 'user-circle', 0],
        ]],
    ];
    // Fatia 22 — ocultacao COSMETICA por papel (a protecao real e o middleware
    // account.role nas rotas + gates de acao): itens que o papel nao acessa
    // somem do menu. Fonte unica: AreaAccess::MAP. Grupo sem itens some junto.
    $navPapelOk = function (string $rota): bool {
        $u = auth()->user();
        if ($u === null) {
            return true;
        }
        try {
            $aid = app(\App\Tenancy\AccountContext::class)->id();
        } catch (\Throwable) {
            return true;
        }

        return \App\Auth\AreaAccess::allows($u, $aid, \App\Auth\AreaAccess::MAP[$rota] ?? 'operador');
    };
    $navGrupos = array_values(array_filter(array_map(function ($g) use ($navPapelOk) {
        $g['items'] = array_values(array_filter($g['items'], fn ($item) => $navPapelOk($item[0])));

        return $g;
    }, $navGrupos), fn ($g) => $g['items'] !== []));

    // Lista PLANA (breadcrumb do header usa; mesmas rotas de sempre).
    $nav = array_merge(...array_map(fn ($g) => $g['items'], $navGrupos));

    // Titulo do header: SO a aba atual (fatia 10 — o prefixo "Menu >" saiu; a
    // navegacao vive na sidebar). "Menu" nunca foi link, era item decorativo.
    $navAtual = collect($nav)->first(fn ($item) => request()->routeIs($item[0]));
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'msgautomation' }}</title>
    <script>
        // Sidebar comeca RETRAIDA por padrao (pedido do Fabio). Depois da primeira visita,
        // a preferencia do usuario persiste — o proprio Flux grava nesta chave a cada toggle.
        if (localStorage.getItem('flux-sidebar-collapsed-desktop') === null) {
            localStorage.setItem('flux-sidebar-collapsed-desktop', 'true');
        }
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
{{-- Prompt 09: h-viewport (100dvh c/ fallback 100vh) — no mobile o 100vh puro e mais
     alto que a area visivel (barra do navegador), criava rolagem de pagina e escondia
     a caixa de envio da conversa. No desktop dvh == vh: nada muda. --}}
<body class="h-viewport bg-zinc-100 text-zinc-900 dark:bg-zinc-950 dark:text-zinc-100 antialiased">
    {{-- Sidebar Flux (free): colapsavel no desktop (icones-so quando retraida, tooltip no hover)
         e overlay no mobile (abre pelo hamburguer do header, fecha no backdrop/navegacao). --}}
    <flux:sidebar sticky collapsible class="border-e border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <flux:sidebar.header>
            <flux:sidebar.brand href="/" name="msgautomation">
                <flux:icon icon="chat-bubble-left-right" variant="mini" class="text-emerald-600 dark:text-emerald-400" />
            </flux:sidebar.brand>
            <flux:sidebar.collapse class="in-data-flux-sidebar-on-desktop:not-in-data-flux-sidebar-collapsed-desktop:-me-2" />
        </flux:sidebar.header>

        <flux:sidebar.nav>
            {{-- Fatia 23 — arvore reagrupada (mesmas rotas; grupo com heading vira
                 flux:sidebar.group expansivel; grupos sem heading rendem soltos). --}}
            @foreach ($navGrupos as $gi => $grupo)
                @if ($grupo['heading'] !== null)
                    <flux:sidebar.group expandable :heading="$grupo['heading']" wire:key="navg-{{ $gi }}">
                        @foreach ($grupo['items'] as [$route, $label, $icon, $badge])
                            <flux:sidebar.item
                                :icon="$icon"
                                :href="route($route)"
                                wire:navigate
                                :badge="$badge > 0 ? ($badge > 99 ? '99+' : (string) $badge) : null"
                                badge:color="amber"
                                badge:variant="solid"
                                :icon-dot="$badge > 0"
                            >{{ $label }}</flux:sidebar.item>
                        @endforeach
                    </flux:sidebar.group>
                @else
                    @foreach ($grupo['items'] as [$route, $label, $icon, $badge])
                        <flux:sidebar.item
                            :icon="$icon"
                            :href="route($route)"
                            wire:navigate
                            :badge="$badge > 0 ? ($badge > 99 ? '99+' : (string) $badge) : null"
                            badge:color="amber"
                            badge:variant="solid"
                            :icon-dot="$badge > 0"
                        >{{ $label }}</flux:sidebar.item>
                    @endforeach
                @endif
            @endforeach
            {{-- Prompt 22: administracao de tenants — SO pro super-admin da plataforma.
                 Fatia 23: rotulo de negocio 'Empresas' (rota identica). --}}
            @if (auth()->user()?->is_platform_admin)
                <flux:sidebar.item icon="building-office-2" :href="route('admin.tenants')" wire:navigate>Empresas</flux:sidebar.item>
            @endif
        </flux:sidebar.nav>
    </flux:sidebar>

    {{-- Header slim: hamburguer (mobile), breadcrumb de contexto e o cluster de estado
         (conta ativa, conexao, robo ON/OFF, sair) — mesmo conteudo do cabecalho antigo. --}}
    <header data-flux-header class="[grid-area:header] z-10 flex min-h-12 flex-wrap items-center gap-x-3 gap-y-1 border-b border-zinc-200 bg-white px-3 py-1.5 dark:border-zinc-800 dark:bg-zinc-900">
        <flux:sidebar.toggle class="-ms-1 lg:hidden" icon="bars-2" />
        @if ($navAtual)
            <flux:breadcrumbs>
                <flux:breadcrumbs.item>{{ $navAtual[1] }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>
        @endif
        <div class="ms-auto flex flex-wrap items-center gap-3 text-xs">
            {{-- MT-1: seletor de conta ativa (sessao) — invisivel com 1 conta --}}
            @auth
                @php $contasDoUsuario = auth()->user()->accounts()->orderBy('account_user.id')->get(['accounts.id', 'accounts.name']); @endphp
                @if ($contasDoUsuario->count() > 1)
                    <form method="POST" action="{{ route('conta.ativa') }}">
                        @csrf
                        <select name="account_id" onchange="this.form.submit()" aria-label="Conta ativa"
                            class="rounded-md border border-zinc-300 bg-white px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800">
                            @foreach ($contasDoUsuario as $contaOpt)
                                <option value="{{ $contaOpt->id }}" @selected((int) session('tenancy.account_id') === (int) $contaOpt->id)>{{ $contaOpt->name }}</option>
                            @endforeach
                        </select>
                    </form>
                    <span class="text-zinc-400">|</span>
                @endif
            @endauth
            <livewire:status-conexao />
            <span class="text-zinc-400">|</span>
            <span class="text-zinc-500">Robo:</span>
            @if ($robo)
                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 font-medium text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                    <span class="size-1.5 rounded-full bg-emerald-500"></span> ON
                </span>
            @else
                <span class="inline-flex items-center gap-1 rounded-full bg-zinc-200 px-2 py-0.5 font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                    <span class="size-1.5 rounded-full bg-zinc-400"></span> OFF
                </span>
            @endif
            @auth
                <span class="text-zinc-400">|</span>
                {{-- Fatia 2: modo de operacao (Pessoal/Automatico) — Livewire, persiste
                     SERVER-SIDE por conta (o pipeline le na Fatia 4; por ora so estado). --}}
                <livewire:operation-mode-toggle />
            @endauth
            <span class="text-zinc-400">|</span>
            {{-- Prompt 30: alternar tema claro/escuro. Reusa o appearance do Flux
                 (localStorage['flux.appearance']; anti-flash pelo @fluxAppearance no head).
                 'dark' e reativo (Alpine) e o setter $flux.appearance aplica+persiste. --}}
            <button type="button" title="Alternar tema" aria-label="Alternar tema claro/escuro"
                x-data="{ dark: document.documentElement.classList.contains('dark') }"
                @click="dark = ! dark; $flux.appearance = dark ? 'dark' : 'light'"
                class="inline-flex items-center rounded-md px-2 py-1 text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-800 dark:hover:bg-zinc-800 dark:hover:text-zinc-200">
                <flux:icon icon="sun" variant="micro" x-show="dark" x-cloak />
                <flux:icon icon="moon" variant="micro" x-show="! dark" x-cloak />
            </button>
            <span class="text-zinc-400">|</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-800 dark:hover:bg-zinc-800 dark:hover:text-zinc-200" title="Sair">
                    <flux:icon icon="arrow-right-start-on-rectangle" variant="micro" /> Sair
                </button>
            </form>
        </div>
    </header>

    <main data-flux-main class="[grid-area:main] flex min-h-0 flex-col overflow-hidden">
        @unless ($robo)
            {{-- Banner proeminente: robo OFF nao responde ninguem (kill switch). --}}
            <div class="shrink-0 border-b border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                <div class="flex items-center gap-2">
                    <flux:icon icon="exclamation-triangle" variant="micro" class="shrink-0" />
                    <span><strong>Robo desligado</strong> — nao responde ninguem automaticamente. A auto-resposta so reage a mensagens <strong>recebidas</strong> de contatos aprovados (on); nunca as mensagens que voce mesmo envia. Para ligar: <a href="{{ route('configuracoes') }}" wire:navigate class="font-medium underline">Configuracoes</a>.</span>
                </div>
            </div>
        @endunless

        {{-- Prompt 18A: a regiao de conteudo ROLA (overflow-y-auto) — Perfil/Logs/Painel
             exibem tudo. Conversas e Contatos se auto-contem (h-full + scroll interno):
             preenchem exatamente esta altura, entao nao geram scroll duplo aqui. O <main>
             segue overflow-hidden (caixa fixa 100dvh) — sem rolagem horizontal de pagina. --}}
        <div class="min-h-0 flex-1 overflow-y-auto">
            {{ $slot }}
        </div>
    </main>

    {{-- Toasts globais (Alpine ouve eventos 'toast' despachados pelos componentes Livewire). --}}
    <div
        x-data="{ toasts: [] }"
        @toast.window="
            const id = Date.now() + Math.random();
            toasts.push({ id, message: $event.detail.message ?? $event.detail[0]?.message, type: $event.detail.type ?? $event.detail[0]?.type ?? 'success' });
            setTimeout(() => toasts = toasts.filter(t => t.id !== id), 3500)
        "
        class="fixed bottom-4 right-4 z-[60] flex flex-col gap-2"
    >
        <template x-for="t in toasts" :key="t.id">
            <div
                x-transition
                class="rounded-lg px-4 py-2 text-sm text-white shadow-lg"
                :class="t.type === 'error' ? 'bg-red-600' : 'bg-zinc-900 dark:bg-zinc-700'"
                x-text="t.message"
            ></div>
        </template>
    </div>

    @fluxScripts
</body>
</html>
