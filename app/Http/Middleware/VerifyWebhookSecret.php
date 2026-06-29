<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Valida a origem do webhook por um header secreto, comparado em TEMPO CONSTANTE
 * (hash_equals). Sem secret configurado ou header divergente -> 401.
 *
 * Em dev o app fica so em localhost/LAN (sem internet), mas a validacao vale sempre.
 */
class VerifyWebhookSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.webhook.secret', '');
        $header = (string) config('services.webhook.header', 'X-Webhook-Secret');
        $provided = (string) $request->header($header, '');

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            abort(401, 'Webhook nao autorizado.');
        }

        return $next($request);
    }
}
