<?php

namespace App\Http\Middleware;

use App\Auth\AreaAccess;
use App\Models\Account;
use App\Tenancy\AccountContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fatia 26 — gate de ACESSO da conta suspensa/cancelada ('account.operational').
 * Semantica travada: o OWNER loga mas so alcanca a tela de billing (pagar/
 * reativar) — este middleware cobre TODAS as rotas do painel exceto ela (e as
 * de sessao: logout, aviso de e-mail). Operador de conta suspensa: 403 com
 * recado (a acao de pagar e do dono). Platform admin passa (gestao da
 * plataforma nao pode ser trancada por billing de tenant). NADA e apagado:
 * pagamento confirmado (webhook) -> 'active' -> este gate volta a liberar.
 * Registrado tambem como middleware persistente do Livewire (updates de
 * componente re-aplicam, como o EnsureAccountRole da Fatia 22).
 */
class EnsureAccountOperational
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || $user->is_platform_admin) {
            return $next($request);
        }

        $context = app(AccountContext::class);
        if (! $context->has()) {
            return $next($request); // sem conta resolvida: outros gates decidem
        }

        $conta = Account::query()->find($context->id());
        if ($conta === null || $conta->podeOperar()) {
            return $next($request);
        }

        if (AreaAccess::allows($user, $conta->id, 'owner')) {
            return redirect()->route('billing');
        }

        abort(403, 'Conta suspensa por pendencia de pagamento. Fale com o dono da conta.');
    }
}
