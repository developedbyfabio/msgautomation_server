<?php

namespace App\Livewire\Servidores;

use App\Auth\AreaAccess;
use App\Servers\Incident;
use App\Servers\IncidentManager;
use App\Servers\Server;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Servidores S2 — incidentes (owner-only). Lista com filtro por estado e por
 * servidor + ACK (silencia repeticao; o incidente segue aberto e monitorado —
 * quem fecha e a normalizacao, via avaliacao). Somente leitura fora do ack.
 */
#[Layout('components.layouts.app')]
class Incidentes extends Component
{
    /** abertos = firing|acknowledged. */
    public string $filtro = 'abertos'; // abertos|todos|resolvidos

    public ?int $servidorId = null;

    public function setFiltro(string $filtro): void
    {
        $this->filtro = in_array($filtro, ['abertos', 'todos', 'resolvidos'], true) ? $filtro : 'abertos';
    }

    public function ack(int $id, IncidentManager $incidents): void
    {
        // Rota ja e owner-only; acao Livewire e forjavel — gate de novo.
        AreaAccess::authorizeOwnerAction();

        $incident = Incident::query()->findOrFail($id); // escopo por conta
        $incidents->acknowledge($incident, (int) auth()->id());
        $this->dispatch('toast', message: 'Incidente reconhecido — re-avisos pausados.');
    }

    /** Reativa os avisos de um incidente reconhecido (re-avisos por cadencia voltam). */
    public function reactivate(int $id, IncidentManager $incidents): void
    {
        AreaAccess::authorizeOwnerAction();

        $incident = Incident::query()->findOrFail($id);
        $incidents->reactivate($incident);
        $this->dispatch('toast', message: 'Avisos reativados — voltam a repetir conforme a cadencia da regra.');
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
