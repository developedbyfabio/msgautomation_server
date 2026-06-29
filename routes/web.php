<?php

use App\Http\Controllers\EvolutionWebhookController;
use App\Livewire\Configuracoes;
use App\Livewire\Contatos;
use App\Livewire\Conversas;
use App\Livewire\Regras;
use Illuminate\Support\Facades\Route;

// UI (Camada 4) — dev, acessada por tunel SSH em localhost. Sem auth (dev).
Route::redirect('/', '/conversas');
Route::get('/conversas', Conversas::class)->name('conversas');
Route::get('/contatos', Contatos::class)->name('contatos');
Route::get('/regras', Regras::class)->name('regras');
Route::get('/configuracoes', Configuracoes::class)->name('configuracoes');

// Webhook da Evolution (Camada 1): valida origem -> enfileira -> 200.
Route::post('/webhook/evolution', EvolutionWebhookController::class)
    ->middleware('webhook.secret')
    ->name('webhook.evolution');
