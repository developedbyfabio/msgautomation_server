<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessIncomingWhatsappMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recebe o webhook da Evolution. Fluxo: origem ja validada (middleware) ->
 * ENFILEIRA o job -> responde 200 rapido. Nada e processado no request.
 *
 * Camada 1: somente recebe. Nao responde mensagem, nao envia, sem IA.
 */
class EvolutionWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->json()->all() ?: $request->all();

        ProcessIncomingWhatsappMessage::dispatch($payload);

        return response()->json(['status' => 'queued'], 200);
    }
}
