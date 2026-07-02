<?php

namespace App\Livewire;

use App\Metrics\PainelMetrics;
use App\Tenancy\AccountContext;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * M-1 — /painel: visao do dono com os numeros que o sistema JA registra. Leitura
 * PURA (nada de dominio e escrito; so cache de 60s por conta+periodo). SEM
 * polling: botao "Atualizar" (dados agregados nao mudam a cada segundo; o cache
 * de 60s tornaria polling curto inutil e polling longo confunde — decisao
 * documentada no relatorio da fatia).
 */
#[Layout('components.layouts.app')]
class Painel extends Component
{
    public string $periodo = '7d'; // hoje | 7d | 30d

    public function setPeriodo(string $periodo): void
    {
        if (array_key_exists($periodo, PainelMetrics::PERIODOS)) {
            $this->periodo = $periodo;
        }
    }

    /** Botao Atualizar: derruba o cache do periodo atual e re-le. */
    public function atualizar(): void
    {
        Cache::forget('painel:' . $this->accountId() . ':' . $this->periodo);
        $this->dispatch('toast', message: 'Painel atualizado.');
    }

    private function accountId(): int
    {
        return app(AccountContext::class)->id();
    }

    public function render(PainelMetrics $metrics)
    {
        return view('livewire.painel', [
            'dados' => $metrics->dados($this->accountId(), $this->periodo),
            'periodos' => PainelMetrics::PERIODOS,
        ]);
    }
}
