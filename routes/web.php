<?php

use App\Http\Controllers\EvolutionWebhookController;
use App\Livewire\Configuracoes;
use App\Livewire\Contatos;
use App\Livewire\Conversas;
use App\Livewire\Login;
use App\Livewire\Regras;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// S2 — login single-user. A UI estava aberta na LAN (0.0.0.0:8080) sem auth.
Route::middleware('guest')->group(function () {
    Route::get('/login', Login::class)->name('login');
});

Route::post('/logout', function () {
    Auth::logout();
    session()->invalidate();
    session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');

// UI (Camada 4) — toda atras de auth.
Route::middleware('auth')->group(function () {
    Route::redirect('/', '/conversas');
    Route::get('/conversas', Conversas::class)->name('conversas');
    Route::get('/contatos', Contatos::class)->name('contatos');
    Route::get('/regras', Regras::class)->name('regras');
    Route::get('/configuracoes', Configuracoes::class)->name('configuracoes');
});

// Webhook da Evolution (Camada 1): valida origem -> enfileira -> 200.
Route::post('/webhook/evolution', EvolutionWebhookController::class)
    ->middleware('webhook.secret')
    ->name('webhook.evolution');
