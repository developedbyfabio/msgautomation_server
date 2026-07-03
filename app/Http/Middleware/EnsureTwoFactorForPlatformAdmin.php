<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prompt 29 — 2FA OBRIGATORIO pro super-admin na area de administracao. Roda junto
 * do 'platform.admin' nas rotas /admin/*. Se o usuario e super-admin e NAO tem 2FA
 * confirmado (Fortify: two_factor_secret + two_factor_confirmed_at, via
 * hasEnabledTwoFactorAuthentication com confirm=true) -> mandado pro /perfil pra
 * ativar. Tenant comum NAO e afetado (2FA segue opt-in fora de /admin/*); quem nao
 * e super-admin passa reto aqui (o 403 e do 'platform.admin').
 */
class EnsureTwoFactorForPlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->is_platform_admin && ! $user->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('perfil')
                ->with('aviso', 'Ative o 2FA para acessar a administracao da plataforma.');
        }

        return $next($request);
    }
}
