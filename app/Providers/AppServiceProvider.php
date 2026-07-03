<?php

namespace App\Providers;

use App\Ai\Drivers\GeminiDriver;
use App\Contracts\AiClassifier;
use App\Channels\Evolution\EvolutionProvider;
use App\Channels\ProviderRegistry;
use App\Contracts\WhatsappGateway;
use App\Tenancy\AccountContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // CH-1 — canais multi-provedor: resolucao POR CANAL via registry.
        $this->app->singleton(ProviderRegistry::class);
        $this->app->singleton(EvolutionProvider::class);
        // Alias DEPRECADO do webhook (normalizeIncoming): o job segue recebendo o
        // mesmo contrato; morre quando a rota resolver o provider (CH-2).
        $this->app->bind(WhatsappGateway::class, EvolutionProvider::class);

        // Classificador de IA (Camada 3): hoje Gemini; contrato abstrato pra trocar depois.
        $this->app->bind(AiClassifier::class, GeminiDriver::class);

        // MT-0 — contexto de conta: UM por request/processo.
        $this->app->singleton(AccountContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // MT-0 — higiene do worker: NENHUM job herda contexto de conta do anterior
        // (worker e processo longevo; cada job define o proprio contexto no handle).
        // push/pop (pilha) em vez de clear: com fila SYNC, um listener/job aninhado
        // nunca apaga o contexto do job pai (restaurado ao fim, sucesso ou erro).
        Queue::before(fn () => app(AccountContext::class)->push());
        Queue::after(fn () => app(AccountContext::class)->pop());
        Queue::exceptionOccurred(fn () => app(AccountContext::class)->pop());

        // Kanban K-1 — eventos de dominio -> listener EM FILA (observador puro;
        // falha isolada no listener, nunca no pipeline).
        Event::listen([
            \App\Events\IncomingMessageStored::class,
            \App\Events\AutoReplySent::class,
            \App\Events\ManualMessageSent::class,
            \App\Events\FlowNodeReached::class,
            \App\Events\AiDecisionRecorded::class,
        ], \App\Listeners\UpdateKanbanFromEvent::class);

        // S1 (fuso): armazenamento em UTC, EXIBICAO em America/Sao_Paulo. Esta macro
        // converte qualquer Carbon (received_at/sent_at/created_at vem em UTC do banco)
        // para o fuso de exibicao SO na hora de formatar na UI. Nao toca no storage nem
        // nos freios/janela (que seguem em config('app.timezone') = UTC).
        Carbon::macro('paraExibicao', function () {
            /** @var Carbon $this */
            return $this->copy()->setTimezone(config('app.display_timezone'));
        });

        // Prompt 02 — /logs: eventos de CANAL (mudanca de status) e ERROS do
        // sistema (warning+) viram linhas em system_events. Best-effort com
        // anti-loop: gravar evento NUNCA pode derrubar (nem re-disparar) o log.
        \App\Models\Channel::updated(function (\App\Models\Channel $canal) {
            if (! $canal->wasChanged('status')) {
                return;
            }
            try {
                \App\Models\SystemEvent::withoutAccountScope()->create([
                    'account_id' => $canal->account_id,
                    'channel_id' => $canal->id,
                    'type' => 'canal',
                    'level' => $canal->status === 'connected' ? 'info' : 'warning',
                    'title' => "Canal {$canal->instance} ({$canal->provider}): " . ($canal->getOriginal('status') ?: '?') . " -> {$canal->status}",
                    'occurred_at' => now(),
                ]);
            } catch (\Throwable) {
                // best-effort
            }
        });

        \Illuminate\Support\Facades\Log::listen(function (\Illuminate\Log\Events\MessageLogged $e) {
            static $gravando = false;
            if ($gravando || ! in_array($e->level, ['warning', 'error', 'critical', 'alert', 'emergency'], true)) {
                return;
            }
            $gravando = true;
            try {
                // So a mensagem (contexto DESCARTADO — pode conter dado sensivel).
                \App\Models\SystemEvent::global($e->level === 'warning' ? 'warning' : 'error', $e->message);
            } finally {
                $gravando = false;
            }
        });
    }
}
