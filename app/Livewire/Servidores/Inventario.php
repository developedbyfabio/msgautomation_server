<?php

namespace App\Livewire\Servidores;

use App\Auth\AreaAccess;
use App\Models\Secret;
use App\Servers\AgentToken;
use App\Servers\MetricsBuffer;
use App\Servers\Server;
use App\Tenancy\AccountContext;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Servidores S1 — inventario (CRUD) + token do agente. Ferramenta INTERNA do
 * dono: rota owner-only (middleware) e TODAS as acoes com gate server-side
 * (authorizeOwnerAction — acao Livewire e forjavel; padrao do Cofre).
 *
 * Token: gerado na criacao/regeneracao (AgentToken), claro SO no Cofre;
 * exibido UMA vez ($plainToken vive so no estado do componente ate fechar o
 * modal — nunca persiste em tabela/log). Regenerar invalida o anterior.
 *
 * Selo "recebendo dados?": derivado APENAS do last_seen_at (informativo) —
 * nenhuma avaliacao de alerta aqui (S2).
 */
#[Layout('components.layouts.app')]
class Inventario extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $host = '';

    public string $os = 'linux';

    public string $grupo = '';

    public ?int $confirmingDeleteId = null;

    public ?int $confirmingRegenId = null;

    // Token exibido UMA vez (criacao/regeneracao). Nao persiste alem do modal.
    public ?string $plainToken = null;

    public string $plainTokenFor = '';

    public ?int $plainTokenServerId = null; // a qual servidor o $plainToken pertence

    // Tutorial de instalacao (sem exigir token fresco na mao).
    public ?int $installServerId = null;

    protected function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('servers', 'name')
                    ->where('account_id', $this->accountId())
                    ->ignore($this->editingId),
            ],
            'host' => 'nullable|string|max:150',
            'os' => 'required|string|in:'.implode(',', Server::OSES), // v1: linux
            'grupo' => 'nullable|string|max:60',
        ];
    }

    protected array $messages = [
        'name.unique' => 'Ja existe um servidor com este nome.',
        'os.in' => 'Por enquanto so Linux e suportado (Windows em fatia futura).',
    ];

    public function novo(): void
    {
        $this->reset(['editingId', 'name', 'host', 'grupo']);
        $this->os = 'linux';
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $s = Server::query()->findOrFail($id); // escopo por conta ja aplicado
        $this->editingId = $s->id;
        $this->name = (string) $s->name;
        $this->host = (string) $s->host;
        $this->os = (string) $s->os;
        $this->grupo = (string) $s->grupo;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->reset(['editingId', 'name', 'host', 'grupo']);
        $this->resetValidation();
    }

    public function save(AgentToken $tokens): void
    {
        AreaAccess::authorizeOwnerAction();
        $this->validate();

        $campos = [
            'name' => trim($this->name),
            'host' => trim($this->host) !== '' ? trim($this->host) : null,
            'os' => $this->os,
            'grupo' => trim($this->grupo) !== '' ? trim($this->grupo) : null,
        ];

        if ($this->editingId) {
            Server::query()->findOrFail($this->editingId)->update($campos);
            $this->closeForm();
            $this->dispatch('toast', message: 'Servidor atualizado.');

            return;
        }

        $server = Server::create($campos + ['account_id' => $this->accountId()]);

        // Token gerado agora e exibido UMA vez; o claro fica no Cofre.
        $this->plainToken = $tokens->issue($server);
        $this->plainTokenFor = $server->name;
        $this->plainTokenServerId = $server->id;
        $this->closeForm();
        $this->dispatch('toast', message: 'Servidor cadastrado.');
    }

    public function toggleEnabled(int $id): void
    {
        AreaAccess::authorizeOwnerAction();
        $s = Server::query()->findOrFail($id);
        $s->update(['enabled' => ! $s->enabled]);
        $this->dispatch('toast', message: $s->enabled ? 'Ingestao reativada.' : 'Ingestao desativada (o agente recebera 403).');
    }

    // ---- Regenerar token (invalida o anterior) ------------------------------

    public function askRegenerate(int $id): void
    {
        $this->confirmingRegenId = $id;
    }

    public function cancelRegenerate(): void
    {
        $this->confirmingRegenId = null;
    }

    public function regenerateConfirmed(AgentToken $tokens): void
    {
        AreaAccess::authorizeOwnerAction();
        if ($this->confirmingRegenId) {
            $s = Server::query()->findOrFail($this->confirmingRegenId);
            $this->plainToken = $tokens->issue($s); // hash novo: o antigo ja da 401
            $this->plainTokenFor = $s->name;
            $this->plainTokenServerId = $s->id;
            $this->dispatch('toast', message: 'Token regenerado — o anterior parou de valer.');
        }
        $this->confirmingRegenId = null;
    }

    /** Fecha o modal do token e DESCARTA o claro do estado do componente. */
    public function dismissToken(): void
    {
        $this->plainToken = null;
        $this->plainTokenFor = '';
        $this->plainTokenServerId = null;
    }

    // ---- Tutorial de instalacao ---------------------------------------------

    public function verInstalacao(int $id): void
    {
        $this->installServerId = $id;
    }

    public function fecharInstalacao(): void
    {
        $this->installServerId = null;
    }

    /** URL do instalador (o `curl | sh`). */
    public function installUrl(): string
    {
        return route('servidores.agente.instalar');
    }

    /**
     * Comando de instalacao de UMA linha. $token embutido quando disponivel em
     * claro (logo apos criar/regenerar); senao placeholder <SEU_TOKEN> (o token
     * so aparece uma vez — padrao do Cofre; regenerar gera um novo).
     */
    public function comandoInstalacao(?string $token): string
    {
        $t = ($token !== null && $token !== '') ? $token : '<SEU_TOKEN>';

        return 'curl -fsSL '.$this->installUrl()
            .' | sudo AGENT_URL='.route('webhook.servers.ingest')
            .' AGENT_TOKEN='.$t.' sh';
    }

    /**
     * Comando de ATUALIZACAO para servidores JA instalados: baixa a versao nova
     * do coletor e troca o binario, PRESERVANDO o config (token) e o timer. NAO
     * pede token (o coletor.sh e publico, sem segredo). Depois desta troca, o
     * agente novo passa a ter `sudo msgautomation-agent-update` pra updates
     * futuros. Download em temp + mv = seguro mesmo se o timer disparar no meio.
     */
    public function comandoAtualizacao(): string
    {
        return 'curl -fsSL '.route('servidores.agente.coletor')
            .' -o /tmp/msgautomation-agent.new && chmod 755 /tmp/msgautomation-agent.new'
            .' && sudo mv /tmp/msgautomation-agent.new /usr/local/bin/msgautomation-agent';
    }

    // ---- Excluir -------------------------------------------------------------

    public function confirmDelete(int $id): void
    {
        $this->confirmingDeleteId = $id;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    public function deleteConfirmed(MetricsBuffer $buffer): void
    {
        AreaAccess::authorizeOwnerAction();
        if ($this->confirmingDeleteId) {
            $s = Server::query()->find($this->confirmingDeleteId);
            if ($s) {
                // Remove tambem o segredo do Cofre (nao deixar token orfao) e o buffer.
                if ($s->agent_token_secret_ref) {
                    Secret::query()
                        ->where('account_id', $s->account_id)
                        ->where('nome', $s->agent_token_secret_ref)
                        ->delete();
                }
                $buffer->forget($s->id);
                $s->delete();
                $this->dispatch('toast', message: 'Servidor excluido.');
            }
        }
        $this->confirmingDeleteId = null;
    }

    // ---- Selo "recebendo dados?" (informativo — sem logica de alerta) --------

    /** [rotulo, cor] a partir do last_seen_at. 90s = 3x a cadencia esperada de 30s. */
    public function selo(?Carbon $lastSeen): array
    {
        if ($lastSeen === null) {
            return ['Aguardando primeiro contato', 'zinc'];
        }
        if ($lastSeen->gt(now()->subSeconds(90))) {
            return ['Recebendo dados', 'emerald'];
        }

        return ['Sem dados ha '.$this->gapHumano($lastSeen), 'amber'];
    }

    private function gapHumano(Carbon $desde): string
    {
        $min = (int) abs(now()->diffInMinutes($desde));
        if ($min < 1) {
            return 'menos de 1 min';
        }
        if ($min < 120) {
            return $min.' min';
        }
        $horas = intdiv($min, 60);
        if ($horas < 48) {
            return $horas.' h';
        }

        return intdiv($horas, 24).' dias';
    }

    private function accountId(): int
    {
        return app(AccountContext::class)->id();
    }

    public function render()
    {
        $servers = Server::query()->orderBy('name')->get();

        $deleting = $this->confirmingDeleteId ? Server::query()->find($this->confirmingDeleteId) : null;
        $regenerating = $this->confirmingRegenId ? Server::query()->find($this->confirmingRegenId) : null;
        $installServer = $this->installServerId ? Server::query()->find($this->installServerId) : null;

        return view('livewire.servidores.inventario', [
            'servers' => $servers,
            'deleting' => $deleting,
            'regenerating' => $regenerating,
            'installServer' => $installServer,
        ]);
    }
}
