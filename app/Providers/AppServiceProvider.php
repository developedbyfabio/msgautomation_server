<?php

namespace App\Providers;

use App\Contracts\WhatsappGateway;
use App\Whatsapp\Drivers\EvolutionDriver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Abstracao de driver: hoje Evolution; troca de provedor sem mexer no resto.
        $this->app->bind(WhatsappGateway::class, EvolutionDriver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
