<?php

namespace App\Http\Middleware;

use App\Tenancy\AccountContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MT-0 — define o contexto de conta no inicio de TODO request web. FASE 1: a
 * conta unica (via fallback centralizado do AccountContext). MT-1: este e o
 * UNICO ponto a trocar — a conta passara a vir do usuario logado (membership).
 * Rotas de webhook nao passam aqui: o job resolve a conta pela instancia.
 */
class SetAccountContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(AccountContext::class);

        if (! $context->has()) {
            try {
                $context->id(); // fase 1: resolve e fixa a conta unica
            } catch (\App\Tenancy\MissingAccountContextException) {
                // Sem conta criada ainda (bootstrap/instalacao): paginas que nao
                // tocam models de dominio seguem; as demais falharao alto na query.
            }
        }

        return $next($request);
    }
}
