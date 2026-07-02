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
        // Aliases de middleware.
        $middleware->alias([
            'webhook.secret' => VerifyWebhookSecret::class,
            'whatsapp.connected' => EnsureWhatsappConnected::class,
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
