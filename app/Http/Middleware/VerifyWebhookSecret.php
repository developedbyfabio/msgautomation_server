<?php

namespace App\Http\Middleware;

use App\Models\Channel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Valida a origem do webhook. Dois caminhos (MT-0):
 *
 *  1. TOKEN POR CANAL (novo, preferido): rota /webhook/evolution/{token} — o token
 *     identifica/autentica o canal (channels.webhook_token, unico). E o caminho
 *     do multi-tenant (cada instancia com seu token; migracao da URL na MT-2).
 *  2. SECRET GLOBAL no header (RETROCOMPAT, **DEPRECADO**): a URL atual configurada
 *     na Evolution continua valendo — o webhook vivo nao para num deploy. Sera
 *     removido quando todas as instancias migrarem pra URL com token (MT-2).
 *
 * Comparacao em tempo constante (hash_equals). Falhou os dois -> 401.
 */
class VerifyWebhookSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        // Caminho 1 — token por canal na URL (bypass nomeado: lookup de canal e
        // pre-contexto por natureza; quem resolve a CONTA e o job, pela instancia).
        $token = (string) $request->route('token', '');
        if ($token !== '') {
            $conhecido = Channel::withoutAccountScope()
                ->whereNotNull('webhook_token')
                ->where('webhook_token', $token)
                ->exists();

            if ($conhecido) {
                return $next($request);
            }

            abort(401, 'Webhook nao autorizado.');
        }

        // Caminho 2 — secret global no header (DEPRECADO; retrocompat da URL atual).
        $expected = (string) config('services.webhook.secret', '');
        $header = (string) config('services.webhook.header', 'X-Webhook-Secret');
        $provided = (string) $request->header($header, '');

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            abort(401, 'Webhook nao autorizado.');
        }

        return $next($request);
    }
}
