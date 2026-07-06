<?php

use App\Http\Middleware\EnsureWhatsappConnected;
use App\Http\Middleware\SetAccountContext;
use App\Http\Middleware\VerifyWebhookSecret;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Producao atras do Cloudflare Tunnel: o cloudflared entrega em HTTP no
        // loopback e o app so descobre o HTTPS real pelo X-Forwarded-Proto. Sem
        // confiar no proxy, as URLs absolutas (Livewire/assets) saem http:// e o
        // browser bloqueia o submit como mixed content. 127.0.0.1 e o unico
        // proxy possivel (a porta 8080 nao e exposta; so o tunel entra).
        $middleware->trustProxies(at: ['127.0.0.1']);

        // Aliases de middleware.
        $middleware->alias([
            'webhook.secret' => VerifyWebhookSecret::class,
            'whatsapp.connected' => EnsureWhatsappConnected::class,
            'platform.admin' => \App\Http\Middleware\EnsurePlatformAdmin::class, // Prompt 22
            'require.2fa.admin' => \App\Http\Middleware\EnsureTwoFactorForPlatformAdmin::class, // Prompt 29
            'account.role' => \App\Http\Middleware\EnsureAccountRole::class, // Fatia 22: papel por conta
            'account.operational' => \App\Http\Middleware\EnsureAccountOperational::class, // Fatia 26: conta suspensa -> so billing
        ]);

        // MT-0 — contexto de conta em todo request web (fase 1: conta unica;
        // MT-1: conta do usuario logado). Webhook nao passa aqui (o job resolve
        // a conta pela instancia do payload).
        $middleware->web(append: [SetAccountContext::class]);

        // O webhook externo nao tem token CSRF — isenta a rota.
        $middleware->validateCsrfTokens(except: [
            'webhook/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
