<?php

use App\Http\Controllers\EvolutionWebhookController;
use App\Livewire\Conexao;
use App\Livewire\Configuracoes;
use App\Livewire\Conhecimento;
use App\Livewire\Contatos;
use App\Livewire\Conversas;
use App\Livewire\Fluxos;
use App\Livewire\Login;
use App\Livewire\Regras;
use App\Livewire\Revisao;
use App\Livewire\Senhas;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// S2 — login single-user. A UI estava aberta na LAN (0.0.0.0:8080) sem auth.
Route::middleware('guest')->group(function () {
    Route::get('/login', Login::class)->name('login');

    // Fatia 25 — cadastro publico PF/PJ (self-signup): provisiona tenant em
    // trial via RegisterTenant (transacao atomica) com rate limiting proprio.
    Route::get('/cadastro', \App\Livewire\Cadastro::class)->name('cadastro');

    // Prompt 01 — 2FA: tela do desafio. O POST e do Fortify (two-factor.login.store,
    // throttle 'two-factor'). Sem desafio pendente na sessao, volta pro login.
    Route::get('/two-factor-challenge', fn () => session()->has('login.id')
        ? view('auth.two-factor-challenge')
        : redirect()->route('login'))->name('two-factor.login');
});

// MT-1: troca da conta ATIVA (sessao). So contas do VINCULO do usuario (403 fora).
Route::post('/conta-ativa', function (\Illuminate\Http\Request $request) {
    $id = (int) $request->input('account_id');
    abort_unless($request->user()->accounts()->whereKey($id)->exists(), 403, 'Conta fora do seu vinculo.');
    $request->session()->put('tenancy.account_id', $id);

    return redirect()->route('conversas');
})->middleware('auth')->name('conta.ativa');

Route::post('/logout', function () {
    Auth::logout();
    session()->invalidate();
    session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');

// Fatia 25 — aviso "confirme seu e-mail": atras de auth mas FORA do gate
// 'verified' (e a UNICA pagina que o recem-cadastrado ve antes de confirmar).
// O GET verify/{id}/{hash} (link assinado) e o POST verification-notification
// (reenvio, throttle 6/min) sao do Fortify (Features::emailVerification).
Route::get('/email/verify', fn () => view('auth.verificar-email'))
    ->middleware('auth')->name('verification.notice');

// Fatia 26 — billing/assinatura: owner-only e FORA do gate 'account.operational'
// (e o UNICO destino que a conta suspensa alcanca: pagar/reativar). O pagamento
// em si e HOSPEDADO no Asaas — nenhum dado de cartao passa por aqui.
Route::middleware(['auth', 'verified', 'account.role:owner'])->group(function () {
    Route::get('/assinatura', \App\Livewire\Billing::class)->name('billing');
});

// UI (Camada 4) — toda atras de auth. /conexao mostra o QR quando a sessao cai;
// as demais paginas exigem o WhatsApp conectado (gate whatsapp.connected).
// Fatia 25: 'verified' no grupo inteiro — usuario de CADASTRO PUBLICO so entra
// no painel depois de confirmar o e-mail (usuarios criados por console/admin
// nascem verificados por construcao; backfill cobriu os pre-existentes).
// Fatia 26: 'account.operational' — conta suspensa/cancelada por billing nao
// opera o painel (owner e redirecionado pra /assinatura; operador ve 403).
Route::middleware(['auth', 'verified', 'account.operational'])->group(function () {
    Route::redirect('/', '/conversas');
    Route::get('/conexao', Conexao::class)->name('conexao');
    // Cofre de senhas: atras de auth, mas fora do gate de conexao (gerenciavel mesmo offline).
    // Fatia 22: OWNER-only (enforcement server-side; menu escondido e so cosmetica).
    Route::get('/senhas', Senhas::class)->middleware('account.role:owner')->name('senhas');
    // Prompt 01 — perfil do usuario logado (email/senha/2FA); fora do gate de conexao.
    Route::get('/perfil', \App\Livewire\Perfil::class)->name('perfil');
    // Prompt 02 — logs/eventos da conta (somente leitura; util mesmo desconectado).
    // Fatia 22: tecnico -> OWNER-only.
    Route::get('/logs', \App\Livewire\Logs::class)->middleware('account.role:owner')->name('logs');
    // Servidores S1 — inventario + token do agente (ferramenta interna do dono,
    // OWNER-only). Fora do gate whatsapp.connected: monitorar servidor nao
    // depende do canal estar conectado.
    Route::get('/servidores', \App\Livewire\Servidores\Inventario::class)->middleware('account.role:owner')->name('servidores');
    // Prompt 04 — serve a midia ENVIADA da conversa. Resolucao EXPLICITA dentro
    // da closure (binding implicito rodaria antes do SetAccountContext): a query
    // escopada por conta garante que midia de outra conta = 404, nunca vaza.
    Route::get('/media/{logId}', function (int $logId) {
        $log = \App\Models\AutoReplyLog::query()->findOrFail($logId);
        abort_unless($log->media_path !== null, 404);

        // Prompt 05: documento baixa/abre com o nome ORIGINAL (path no disco e uuid).
        return \Illuminate\Support\Facades\Storage::disk('local')->response($log->media_path, $log->media_name);
    })->whereNumber('logId')->name('media.show');

    // Prompt 13 — serve a midia RECEBIDA (imagem cheia / audio), escopada por conta:
    // a query passa pelo escopo de conta (SetAccountContext ja rodou), entao midia
    // de outra conta = 404 (findOrFail), nunca vaza. ?thumb=1 devolve a miniatura
    // EMBUTIDA (jpegThumbnail) extraida on-the-fly — tira o base64 do HTML do poll.
    Route::get('/media/incoming/{id}', function (int $id, \Illuminate\Http\Request $request) {
        $msg = \App\Models\IncomingMessage::query()->findOrFail($id);

        if ($request->boolean('thumb')) {
            $bin = \App\Whatsapp\MessagePreview::thumbnailBinary((array) $msg->raw_payload);
            abort_if($bin === null, 404);

            return response($bin, 200, [
                'Content-Type' => 'image/jpeg',
                'Cache-Control' => 'private, max-age=86400',
            ]);
        }

        abort_if($msg->media_path === null, 404);

        return \Illuminate\Support\Facades\Storage::disk('local')
            ->response($msg->media_path, $msg->media_name, ['Content-Type' => $msg->media_mime ?: 'application/octet-stream']);
    })->whereNumber('id')->name('media.incoming');
    // Fatia 22/23: engenharia ESTRUTURAL da automacao -> OWNER-only (operador
    // nem ve — inalterado). Conhecimento saiu do grupo na Fatia 23 (decisao do
    // dono: operador VE; a escrita e barrada pelos gates de acao).
    Route::middleware('account.role:owner')->group(function () {
        // Fluxos (construtor): config, editavel mesmo offline.
        Route::get('/fluxos', Fluxos::class)->name('fluxos');
        // Variaveis (V-1): placeholders configuraveis; config, editavel offline.
        Route::get('/variaveis', \App\Livewire\Variaveis::class)->name('variaveis');
    });
    // Base de conhecimento da IA (Fatia 2): operador VE (Fatia 23); escrita = owner (gate).
    Route::get('/conhecimento', Conhecimento::class)->name('conhecimento');

    // Prompt 22 — administracao de tenants (super-admin da plataforma). UNICO ponto
    // cross-tenant; fora do gate de conexao (nao depende de canal/WhatsApp do tenant).
    // Prompt 29: super-admin + 2FA obrigatorio (require.2fa.admin) na area de admin.
    Route::middleware(['platform.admin', 'require.2fa.admin'])->group(function () {
        Route::get('/admin/tenants', \App\Livewire\Admin\Tenants::class)->name('admin.tenants');
    });

    Route::middleware('whatsapp.connected')->group(function () {
        // M-1: painel do dono (leitura pura dos logs; primeiro item do menu).
        Route::get('/painel', \App\Livewire\Painel::class)->name('painel');
        Route::get('/conversas', Conversas::class)->name('conversas');
        // Kanban K-2: board de conversas (observador puro; mover card e acao humana).
        Route::get('/kanban', \App\Livewire\Kanban::class)->name('kanban');
        Route::get('/contatos', Contatos::class)->name('contatos');
        // Fila de aprovacao da IA (Fatia 3): envia mensagens -> atras do gate de conexao.
        Route::get('/revisao', Revisao::class)->name('revisao');
        // Campanhas proativas (P-2): operador VE (Fatia 23); escrita = owner (gate).
        Route::get('/campanhas', \App\Livewire\Campanhas::class)->name('campanhas');
        // Fatia 22: automacao/config -> OWNER-only (enforcement server-side).
        Route::middleware('account.role:owner')->group(function () {
            Route::get('/regras', Regras::class)->name('regras');
            Route::get('/configuracoes', Configuracoes::class)->name('configuracoes');
        });
    });
});

// Webhook da Evolution (Camada 1): valida origem -> enfileira -> 200.
// Rota SEM token = secret global no header (retrocompat, DEPRECADO — e a URL que a
// Evolution usa hoje; nao muda nesta fatia). Rota COM token = token por canal (MT-0;
// a URL por instancia migra na MT-2).
Route::post('/webhook/evolution', EvolutionWebhookController::class)
    ->middleware('webhook.secret')
    ->name('webhook.evolution');
Route::post('/webhook/evolution/{token}', EvolutionWebhookController::class)
    ->middleware('webhook.secret')
    ->name('webhook.evolution.token');
// CH-2 — canal oficial (Meta): GET = challenge, POST = mensagens (HMAC no middleware).
Route::match(['get', 'post'], '/webhook/cloud/{token}', \App\Http\Controllers\ChannelWebhookController::class)
    ->middleware('webhook.secret')
    ->name('webhook.cloud');

// Fatia 26 — webhook de COBRANCA do Asaas: token proprio validado no controller
// (header asaas-access-token, 401 se invalido), dedup por event id, 200 + job.
// CSRF isento pelo prefixo webhook/* (bootstrap). Tunnel/nginx intocados: a
// rota e da aplicacao e o dominio ja e exposto.
Route::post('/webhook/asaas', \App\Http\Controllers\AsaasWebhookController::class)
    ->name('webhook.asaas');

// Servidores S1 — ingestao de metricas dos agentes coletores (PUSH via HTTPS).
// Token POR SERVIDOR no header X-Agent-Token (claro so no Cofre; sha256 na
// tabela; hash_equals no resolve), rate limit proprio por token/IP (429),
// payload minimo validado (413/422). Grava buffer efemero + last_seen_at e
// responde — NENHUMA avaliacao/envio inline (S2/S3). CSRF isento pelo prefixo
// webhook/* (bootstrap).
Route::post('/webhook/servers/ingest', \App\Http\Controllers\ServerIngestController::class)
    ->middleware('throttle:server-ingest')
    ->name('webhook.servers.ingest');
