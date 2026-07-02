<?php

namespace App\Http\Middleware;

use App\Models\Channel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Valida a origem do webhook: TOKEN POR CANAL na URL (MT-0/MT-2) — o token
 * identifica o canal e o PROVIDER dele verifica (Evolution: o proprio token em
 * tempo constante; Cloud API no CH-2: challenge + HMAC).
 *
 * MT-2 (2026-07-02): o secret global no header foi REMOVIDO — a instancia da
 * conta 1 migrou pra URL por token com validacao real (mensagem organica pela
 * rota nova). A URL antiga (/webhook/evolution sem token) agora e SEMPRE 401.
 * Reversao: git revert deste commit + evolution:webhook:migrate --rollback.
 */
class VerifyWebhookSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        // Caminho 1 — token por canal na URL (bypass nomeado: lookup de canal e
        // pre-contexto por natureza; quem resolve a CONTA e o job, pela instancia).
        // CH-1: o canal resolvido DELEGA a verificacao ao provider dele (Evolution:
        // o proprio token, tempo constante; Cloud API no CH-2: challenge + HMAC).
        $token = (string) $request->route('token', '');
        if ($token !== '') {
            $channel = Channel::withoutAccountScope()
                ->whereNotNull('webhook_token')
                ->where('webhook_token', $token)
                ->first();

            if ($channel !== null && app(\App\Channels\ProviderRegistry::class)
                ->for($channel)->verifyWebhook($request, $channel)) {
                return $next($request);
            }

            abort(401, 'Webhook nao autorizado.');
        }

        // Sem token na URL: 401 SEMPRE (o secret global morreu na MT-2).
        abort(401, 'Webhook nao autorizado.');
    }
}
