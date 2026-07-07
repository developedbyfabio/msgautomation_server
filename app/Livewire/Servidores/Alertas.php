<?php

namespace App\Livewire\Servidores;

use App\Auth\AreaAccess;
use App\Servers\AlertContact;
use App\Servers\AlertRule;
use App\Servers\AlertRuleDefaults;
use App\Servers\Server;
use App\Tenancy\AccountContext;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Servidores S2 — regras de alerta (owner-only). Escopo GLOBAL (padroes da
 * conta, server_id NULL) ou POR SERVIDOR (sobrescritas). Precedencia:
 * especifica > global, inclusive enabled=false (sobrescrita desligada
 * silencia a metrica no servidor). Regras GLOBAIS nao sao removiveis (so
 * editaveis/desligadas); sobrescritas podem ser removidas (volta ao padrao).
 */
#[Layout('components.layouts.app')]
class Alertas extends Component
{
    /** null = padroes globais; id = sobrescritas daquele servidor. */
    public ?int $servidorId = null;

    public ?int $editingId = null;

    public string $editingLabel = '';

    public string $editingMetric = '';

    public ?string $warning_threshold = null;

    public string $critical_threshold = '';

    public string $warning_for_s = '';

    public string $critical_for_s = '';

    public string $resolve_for_s = ''; // A4: debounce de resolucao; vazio = usa warning_for_s

    public string $cooldown_s = '';

    public bool $enabled = true;

    public ?int $confirmingRemoveId = null;

    // ---- Destinatarios (roteamento) ----
    public bool $showContactForm = false;

    public ?int $contactEditingId = null;

    public string $c_name = '';

    public string $c_phone = '';

    public string $c_email = '';

    public string $c_min_level = 'warning';

    public ?int $c_server_id = null;

    public string $c_grupo = '';

    public ?int $confirmingContactDeleteId = null;

    public function mount(): void
    {
        // Padroes garantidos de forma lazy (contas novas; idempotente).
        AlertRuleDefaults::ensureFor($this->accountId());
    }

    // ---- edicao ---------------------------------------------------------------

    public function edit(int $id): void
    {
        $r = AlertRule::query()->findOrFail($id); // escopo por conta
        $this->editingId = $r->id;
        $this->editingMetric = $r->metric;
        $this->editingLabel = (AlertRule::LABELS[$r->metric] ?? $r->metric)
            .($r->isGlobal() ? ' — padrao global' : ' — sobrescrita deste servidor');
        $this->warning_threshold = $r->warning_threshold !== null ? (string) $r->warning_threshold : null;
        $this->critical_threshold = (string) $r->critical_threshold;
        $this->warning_for_s = (string) $r->warning_for_s;
        $this->critical_for_s = (string) $r->critical_for_s;
        $this->resolve_for_s = $r->resolve_for_s !== null ? (string) $r->resolve_for_s : '';
        $this->cooldown_s = (string) $r->cooldown_s;
        $this->enabled = (bool) $r->enabled;
        $this->resetValidation();
    }

    public function closeEdit(): void
    {
        $this->editingId = null;
        $this->resetValidation();
    }

    public function save(): void
    {
        AreaAccess::authorizeOwnerAction();

        // A3/A6 — for_duration limitado a max_for_duration_s: alem disso o buffer
        // recente nao guardaria janela suficiente e a regra nunca dispararia.
        $maxFor = (int) config('servers.max_for_duration_s', 600);

        $dados = $this->validate([
            'warning_threshold' => 'nullable|numeric|min:0|lte:critical_threshold',
            'critical_threshold' => 'required|numeric|min:0',
            'warning_for_s' => "required|integer|min:0|max:{$maxFor}",
            'critical_for_s' => "required|integer|min:0|max:{$maxFor}",
            'resolve_for_s' => "nullable|integer|min:0|max:{$maxFor}",
            'cooldown_s' => 'required|integer|min:0|max:86400',
        ], [
            'warning_threshold.lte' => 'Warning deve ser menor ou igual ao critical.',
            'warning_for_s.max' => "Maximo {$maxFor}s (limite do buffer de avaliacao).",
            'critical_for_s.max' => "Maximo {$maxFor}s (limite do buffer de avaliacao).",
        ]);

        // resolve_for_s vazio -> NULL (usa warning_for_s no avaliador).
        $dados['resolve_for_s'] = $this->resolve_for_s === '' ? null : (int) $this->resolve_for_s;

        $r = AlertRule::query()->findOrFail($this->editingId);
        $r->update($dados + ['enabled' => $this->enabled]);

        $this->closeEdit();
        $this->dispatch('toast', message: 'Regra salva.');
    }

    public function toggleEnabled(int $id): void
    {
        AreaAccess::authorizeOwnerAction();
        $r = AlertRule::query()->findOrFail($id);
        $r->update(['enabled' => ! $r->enabled]);
    }

    // ---- sobrescrita por servidor ----------------------------------------------

    /** Cria a sobrescrita copiando a regra EFETIVA atual e abre a edicao. */
    public function override(string $metric): void
    {
        AreaAccess::authorizeOwnerAction();
        if (! $this->servidorId || ! in_array($metric, AlertRule::METRICS, true)) {
            return;
        }

        $efetiva = $this->efetiva($metric);
        if ($efetiva === null) {
            return;
        }

        $especifica = AlertRule::query()->firstOrCreate(
            ['account_id' => $this->accountId(), 'server_id' => $this->servidorId, 'metric' => $metric],
            $efetiva->only(['warning_threshold', 'critical_threshold', 'warning_for_s', 'critical_for_s', 'cooldown_s', 'enabled']),
        );

        $this->edit($especifica->id);
    }

    public function askRemoveOverride(int $id): void
    {
        $this->confirmingRemoveId = $id;
    }

    public function cancelRemoveOverride(): void
    {
        $this->confirmingRemoveId = null;
    }

    /** Remove a sobrescrita (o servidor volta ao padrao global). Globais nao sao removiveis. */
    public function removeOverrideConfirmed(): void
    {
        AreaAccess::authorizeOwnerAction();
        if ($this->confirmingRemoveId) {
            AlertRule::query()
                ->whereNotNull('server_id') // NUNCA remove regra global
                ->whereKey($this->confirmingRemoveId)
                ->delete();
            $this->dispatch('toast', message: 'Sobrescrita removida — volta ao padrao global.');
        }
        $this->confirmingRemoveId = null;
    }

    // ---- Destinatarios (roteamento por severidade + alvo) ----------------------

    public function novoContato(): void
    {
        $this->reset(['contactEditingId', 'c_name', 'c_phone', 'c_email', 'c_grupo']);
        $this->c_min_level = 'warning';
        $this->c_server_id = null;
        $this->resetValidation();
        $this->showContactForm = true;
    }

    public function editContato(int $id): void
    {
        $c = AlertContact::query()->findOrFail($id);
        $this->contactEditingId = $c->id;
        $this->c_name = (string) $c->name;
        $this->c_phone = (string) $c->phone;
        $this->c_email = (string) $c->email;
        $this->c_min_level = $c->min_level;
        $this->c_server_id = $c->server_id;
        $this->c_grupo = (string) $c->grupo;
        $this->resetValidation();
        $this->showContactForm = true;
    }

    public function closeContato(): void
    {
        $this->showContactForm = false;
        $this->resetValidation();
    }

    public function saveContato(): void
    {
        AreaAccess::authorizeOwnerAction();

        $this->validate([
            'c_name' => 'required|string|max:100',
            'c_phone' => 'required|string|max:30|regex:/^[0-9+\s()-]+$/',
            'c_email' => 'nullable|email|max:150',
            'c_min_level' => 'required|in:warning,critical',
            'c_server_id' => 'nullable|integer|exists:servers,id',
            'c_grupo' => 'nullable|string|max:60',
        ], [
            'c_phone.regex' => 'Telefone: use apenas numeros e + ( ) - espaco.',
        ]);

        AlertContact::query()->updateOrCreate(
            ['id' => $this->contactEditingId, 'account_id' => $this->accountId()],
            [
                'account_id' => $this->accountId(),
                'name' => trim($this->c_name),
                'phone' => preg_replace('/\D/', '', $this->c_phone),
                'email' => trim($this->c_email) !== '' ? trim($this->c_email) : null,
                'min_level' => $this->c_min_level,
                // server_id especifico tem precedencia; senao grupo; senao todos.
                'server_id' => $this->c_server_id,
                'grupo' => $this->c_server_id === null && trim($this->c_grupo) !== '' ? trim($this->c_grupo) : null,
                'enabled' => true,
            ],
        );

        $this->closeContato();
        $this->dispatch('toast', message: 'Destinatario salvo.');
    }

    public function toggleContato(int $id): void
    {
        AreaAccess::authorizeOwnerAction();
        $c = AlertContact::query()->findOrFail($id);
        $c->update(['enabled' => ! $c->enabled]);
    }

    public function askDeleteContato(int $id): void
    {
        $this->confirmingContactDeleteId = $id;
    }

    public function cancelDeleteContato(): void
    {
        $this->confirmingContactDeleteId = null;
    }

    public function deleteContatoConfirmed(): void
    {
        AreaAccess::authorizeOwnerAction();
        if ($this->confirmingContactDeleteId) {
            AlertContact::query()->whereKey($this->confirmingContactDeleteId)->delete();
            $this->dispatch('toast', message: 'Destinatario removido.');
        }
        $this->confirmingContactDeleteId = null;
    }

    // ---- leitura ----------------------------------------------------------------

    /** Regra efetiva da metrica no contexto atual (especifica > global). */
    private function efetiva(string $metric): ?AlertRule
    {
        if ($this->servidorId) {
            $especifica = AlertRule::query()
                ->where('server_id', $this->servidorId)->where('metric', $metric)->first();
            if ($especifica) {
                return $especifica;
            }
        }

        return AlertRule::query()->whereNull('server_id')->where('metric', $metric)->first();
    }

    private function accountId(): int
    {
        return app(AccountContext::class)->id();
    }

    public function render()
    {
        // Linhas por metrica: a efetiva no contexto + a origem (global/sobrescrita).
        $linhas = [];
        foreach (AlertRule::METRICS as $metric) {
            $regra = $this->efetiva($metric);
            if ($regra !== null) {
                $linhas[] = ['metric' => $metric, 'rule' => $regra, 'override' => ! $regra->isGlobal()];
            }
        }

        $removing = $this->confirmingRemoveId ? AlertRule::query()->find($this->confirmingRemoveId) : null;

        $contatos = AlertContact::query()->orderBy('name')->get();
        $contactDeleting = $this->confirmingContactDeleteId
            ? AlertContact::query()->find($this->confirmingContactDeleteId) : null;

        return view('livewire.servidores.alertas', [
            'linhas' => $linhas,
            'servers' => Server::query()->orderBy('name')->get(['id', 'name']),
            'removing' => $removing,
            'contatos' => $contatos,
            'contactDeleting' => $contactDeleting,
            'notificacoesLigadas' => (bool) config('servers.notifications_enabled'),
        ]);
    }
}
