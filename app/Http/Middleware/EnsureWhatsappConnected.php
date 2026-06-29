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
        $status = Channel::query()
            ->where('instance', config('services.evolution.instance'))
            ->value('status');

        if ($status === 'disconnected') {
            return redirect()->route('conexao');
        }

        return $next($request);
    }
}
