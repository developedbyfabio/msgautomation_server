<?php

namespace App\Providers;

use App\Ai\Drivers\GeminiDriver;
use App\Contracts\AiClassifier;
use App\Contracts\WhatsappGateway;
use App\Whatsapp\Drivers\EvolutionDriver;
use Illuminate\Support\Carbon;
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

        // Classificador de IA (Camada 3): hoje Gemini; contrato abstrato pra trocar depois.
        $this->app->bind(AiClassifier::class, GeminiDriver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // S1 (fuso): armazenamento em UTC, EXIBICAO em America/Sao_Paulo. Esta macro
        // converte qualquer Carbon (received_at/sent_at/created_at vem em UTC do banco)
        // para o fuso de exibicao SO na hora de formatar na UI. Nao toca no storage nem
        // nos freios/janela (que seguem em config('app.timezone') = UTC).
        Carbon::macro('paraExibicao', function () {
            /** @var Carbon $this */
            return $this->copy()->setTimezone(config('app.display_timezone'));
        });
    }
}
