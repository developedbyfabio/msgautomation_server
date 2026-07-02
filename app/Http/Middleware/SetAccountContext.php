<?php

namespace App\Http\Middleware;

use App\Tenancy\AccountContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MT-1 — a conta do request web vem do USUARIO LOGADO (vinculo account_user):
 *
 *  1. usuario com vinculo(s): a conta ativa e a da SESSAO se (e SO se) o vinculo
 *     existe — sessao forjada/invalida e RESETADA pra primeira conta do vinculo
 *     (autorizacao por request, nao so por query); com 1 conta, e ela sempre;
 *  2. usuario logado SEM vinculo: 403 claro (logout continua acessivel);
 *  3. sem usuario (login/webhook): nada definido aqui — webhook resolve a conta
 *     pela instancia no job; queries de dominio sem contexto FALHAM ALTO.
 *
 * O fallback de conta unica (fase 1) esta DESLIGADO por default (config/tenancy);
 * a suite legada roda com ele ligado via phpunit.xml (semantica fase-1 preservada
 * nos testes antigos; os testes MT-1 desligam explicitamente).
 */
class SetAccountContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(AccountContext::class);
        $user = $request->user();

        if ($user !== null) {
            $contas = $user->accounts()->orderBy('account_user.id')->pluck('accounts.id');

            if ($contas->isNotEmpty()) {
                // SEMPRE re-resolve do vinculo + sessao — autorizacao POR REQUEST
                // (contexto pre-existente/stale nunca e confiado num request web).
                $ativa = (int) $request->session()->get('tenancy.account_id', 0);
                if (! $contas->contains($ativa)) {
                    // Sessao ausente OU conta forjada fora do vinculo: reseta.
                    $ativa = (int) $contas->first();
                    $request->session()->put('tenancy.account_id', $ativa);
                }
                $context->set($ativa);

                return $next($request);
            }

            if (! config('tenancy.single_account_fallback', false) && ! $request->routeIs('logout')) {
                // MT-1: logado sem vinculo nao opera NADA (o logout fica acessivel).
                abort(403, 'Seu usuario nao esta vinculado a nenhuma conta. Fale com o dono do sistema.');
            }
        }

        if (! $context->has()) {
            try {
                $context->id(); // fase 1/testes legados: fallback centralizado (OFF em producao MT-1)
            } catch (\App\Tenancy\MissingAccountContextException) {
                // Sem conta resolvida (login/bootstrap/webhook): paginas que nao
                // tocam models de dominio seguem; as demais falharao alto na query.
            }
        }

        return $next($request);
    }
}
