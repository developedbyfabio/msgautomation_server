<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessIncomingWhatsappMessage;
use App\Models\Channel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CH-2 — webhook provider-agnostico POR TOKEN (GET e POST). A origem ja foi
 * validada pelo middleware (que delega ao provider do canal: Evolution = token;
 * Cloud API = challenge no GET / HMAC no POST).
 *
 *  - GET: challenge da Meta — responde hub.challenge em texto puro (nada e
 *    processado nem enfileirado);
 *  - POST: ENFILEIRA o job com o HINT do canal (o provider certo normaliza) e
 *    responde 200 rapido. Nada e processado no request.
 *
 * A rota da Evolution (/webhook/evolution/{token}) segue no controller antigo,
 * INTOCADA — o canal vivo do Fabio nao passa por este arquivo.
 */
class ChannelWebhookController extends Controller
{
    public function __invoke(Request $request, string $token): Response
    {
        if ($request->isMethod('GET')) {
            // Challenge (verify_token ja validado no middleware via provider).
            return response((string) $request->query('hub_challenge', ''), 200)
                ->header('Content-Type', 'text/plain');
        }

        $channel = Channel::withoutAccountScope()
            ->where('webhook_token', $token)->first(); // middleware ja garantiu que existe

        $payload = $request->json()->all() ?: $request->all();

        ProcessIncomingWhatsappMessage::dispatch($payload, $channel?->id);

        return response()->json(['status' => 'queued'], 200);
    }
}
