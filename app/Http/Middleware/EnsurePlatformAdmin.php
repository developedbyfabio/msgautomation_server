<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prompt 22 — porta do super-admin da plataforma. So quem tem is_platform_admin
 * acessa a administracao de tenants; qualquer outro (inclusive usuario comum de
 * tenant, logado) recebe 403. Roda depois de 'auth'.
 */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        // Order-independente: sem usuario, deixa o 'auth' redirecionar pro login
        // (nao 403 num guest). So bloqueia usuario LOGADO que nao e super-admin.
        $user = $request->user();
        if ($user !== null) {
            abort_unless((bool) $user->is_platform_admin, 403, 'Acesso restrito ao administrador da plataforma.');
        }

        return $next($request);
    }
}
