<?php

namespace App\Http\Middleware;

use App\Models\Channel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * S3 — gate de conexao. A UI principal exige um canal conectado; senao cai na
 * /conexao. Casos que caem pra /conexao:
 *  - Prompt 27 (Fatia 2): conta SEM canal -> conectar o proprio WhatsApp (o tenant
 *    novo nao ve mais status/canal de outra conta; e levado ao self-service);
 *  - canal DEFINITIVAMENTE desconectado (channels.status = 'disconnected').
 * Estados transitorios (connecting/verificando) passam — nao chutamos por blip.
 * A /conexao NAO usa este middleware (fica fora do grupo) — sem loop de redirect.
 */
class EnsureWhatsappConnected
{
    public function handle(Request $request, Closure $next): Response
    {
        // Sem contexto de conta (bootstrap): nao barra — as queries de dominio
        // decidem/falham alto se for o caso.
        try {
            $accountId = app(\App\Tenancy\AccountContext::class)->id();
        } catch (\App\Tenancy\MissingAccountContextException) {
            return $next($request);
        }

        // Canal DA CONTA (escopado; null = conta sem canal).
        $canal = Channel::defaultFor($accountId);
        if ($canal === null || $canal->status === 'disconnected') {
            return redirect()->route('conexao');
        }

        return $next($request);
    }
}
