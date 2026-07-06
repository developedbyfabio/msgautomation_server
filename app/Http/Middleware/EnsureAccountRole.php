<?php

namespace App\Http\Middleware;

use App\Auth\AreaAccess;
use App\Tenancy\AccountContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fatia 22 — enforcement SERVER-SIDE do papel de conta ('account.role:owner').
 * Esconder do menu e cosmetica; a protecao real e esta: rota owner-only
 * acessada por operador (URL direta) = 403. Roda depois de auth +
 * SetAccountContext (a conta ATIVA ja esta resolvida). Registrado como
 * middleware PERSISTENTE do Livewire: os updates de componente (que nao passam
 * pela rota da pagina) re-aplicam a checagem.
 */
class EnsureAccountRole
{
    public function handle(Request $request, Closure $next, string $minimo = 'owner'): Response
    {
        $user = $request->user();
        if ($user !== null) {
            try {
                $accountId = app(AccountContext::class)->id();
            } catch (\App\Tenancy\MissingAccountContextException) {
                abort(403, 'Nenhuma conta ativa.');
            }

            abort_unless(
                AreaAccess::allows($user, $accountId, $minimo),
                403,
                'Acesso restrito ao dono da conta.',
            );
        }

        return $next($request);
    }
}
