<?php

namespace App\Livewire\Servidores;

use App\Servers\Incident;
use App\Servers\Server;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Servidores — incidentes (owner-only), SOMENTE LEITURA. Ciclo simples:
 * firing -> resolved. Nao ha "reconhecer" — o alerta re-avisa pela cadencia
 * ate normalizar. A tela lista abertos/resolvidos e o historico; sem acoes.
 */
#[Layout('components.layouts.app')]
class Incidentes extends Component
{
    /** abertos = firing (nao-resolvidos). */
    public string $filtro = 'abertos'; // abertos|todos|resolvidos

    public ?int $servidorId = null;

    public function setFiltro(string $filtro): void
    {
        $this->filtro = in_array($filtro, ['abertos', 'todos', 'resolvidos'], true) ? $filtro : 'abertos';
    }

    public function render()
    {
        $incidents = Incident::query()
            ->with('server')
            ->when($this->filtro === 'abertos', fn ($q) => $q->where('status', '!=', Incident::STATUS_RESOLVED))
            ->when($this->filtro === 'resolvidos', fn ($q) => $q->where('status', Incident::STATUS_RESOLVED))
            ->when($this->servidorId, fn ($q) => $q->where('server_id', $this->servidorId))
            ->orderByDesc('started_at')
            ->limit(100)
            ->get();

        return view('livewire.servidores.incidentes', [
            'incidents' => $incidents,
            'servers' => Server::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
