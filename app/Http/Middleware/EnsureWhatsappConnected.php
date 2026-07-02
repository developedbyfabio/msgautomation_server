<?php

namespace App\Http\Middleware;

use App\Models\Channel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * S3 — gate de conexao. Quando o canal esta DEFINITIVAMENTE desconectado
 * (channels.status = 'disconnected', sincronizado pelo StatusConexao/Conexao),
 * a UI principal cai na tela de QR (/conexao). Estados transitorios
 * (connecting/desconhecido/sem canal) passam — nao chutamos o usuario por um
 * blip de rede. /conexao nunca passa por aqui (ela mesma redireciona ao abrir).
 */
class EnsureWhatsappConnected
{
    public function handle(Request $request, Closure $next): Response
    {
        // MT-2: o gate olha o canal DA CONTA do contexto (setado pelo
        // SetAccountContext). Sem contexto/canal (bootstrap): deixa passar —
        // a pagina decide (e queries de dominio falham alto se for o caso).
        try {
            $status = Channel::query()->oldest('id')->value('status');
        } catch (\App\Tenancy\MissingAccountContextException) {
            $status = null;
        }

        if ($status === 'disconnected') {
            return redirect()->route('conexao');
        }

        return $next($request);
    }
}
