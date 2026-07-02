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

// UI (Camada 4) — toda atras de auth. /conexao mostra o QR quando a sessao cai;
// as demais paginas exigem o WhatsApp conectado (gate whatsapp.connected).
Route::middleware('auth')->group(function () {
    Route::redirect('/', '/conversas');
    Route::get('/conexao', Conexao::class)->name('conexao');
    // Cofre de senhas: atras de auth, mas fora do gate de conexao (gerenciavel mesmo offline).
    Route::get('/senhas', Senhas::class)->name('senhas');
    // Fluxos (construtor): config, editavel mesmo offline.
    Route::get('/fluxos', Fluxos::class)->name('fluxos');
    // Base de conhecimento da IA (Fatia 2): config, editavel mesmo offline.
    Route::get('/conhecimento', Conhecimento::class)->name('conhecimento');
    // Variaveis (V-1): placeholders configuraveis; config, editavel offline.
    Route::get('/variaveis', \App\Livewire\Variaveis::class)->name('variaveis');

    Route::middleware('whatsapp.connected')->group(function () {
        // M-1: painel do dono (leitura pura dos logs; primeiro item do menu).
        Route::get('/painel', \App\Livewire\Painel::class)->name('painel');
        Route::get('/conversas', Conversas::class)->name('conversas');
        // Kanban K-2: board de conversas (observador puro; mover card e acao humana).
        Route::get('/kanban', \App\Livewire\Kanban::class)->name('kanban');
        Route::get('/contatos', Contatos::class)->name('contatos');
        Route::get('/regras', Regras::class)->name('regras');
        // Fila de aprovacao da IA (Fatia 3): envia mensagens -> atras do gate de conexao.
        Route::get('/revisao', Revisao::class)->name('revisao');
        // Campanhas proativas (P-2): gate humano draft->preview->aprovar (disparo = P-3).
        Route::get('/campanhas', \App\Livewire\Campanhas::class)->name('campanhas');
        Route::get('/configuracoes', Configuracoes::class)->name('configuracoes');
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
