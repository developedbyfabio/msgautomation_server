<?php

use App\Http\Controllers\EvolutionWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Webhook da Evolution (Camada 1): valida origem -> enfileira -> 200.
Route::post('/webhook/evolution', EvolutionWebhookController::class)
    ->middleware('webhook.secret')
    ->name('webhook.evolution');
