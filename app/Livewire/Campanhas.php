<?php

namespace App\Livewire;

use App\Models\BoardColumn;
use App\Models\CampaignTarget;
use App\Models\Contact;
use App\Models\ProactiveCampaign;
use App\Models\Tag;
use App\Tenancy\AccountContext;
use App\Whatsapp\AutoReply\RuleResponder;
use App\Whatsapp\Proactive\AgendaBuilder;
use App\Whatsapp\Proactive\AudienceResolver;
use App\Whatsapp\Secrets\SecretVault;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Proativas P-2 — /campanhas: campanha como objeto de primeira classe com GATE
 * HUMANO estrutural: draft (edita tudo) -> preview (lista EXATA + excluidos com
 * motivo + mensagem de exemplo; retrato re-resolvido ao abrir) -> aprovar (modal
 * FORTE; congela snapshot: targets pending + agenda com jitter na janela;
 * mensagem/publico TRAVADOS) -> cancelar. Des-aprovar volta a draft e apaga
 * targets pendentes (na P-3, bloqueado se houver enviado).
 *
 * NADA dispara nesta fatia — o tick/job de envio e a P-3. {senha:} PROIBIDO na
 * mensagem (validacao no save, coerente com o ProactiveGuard).
 */
#[Layout('components.layouts.app')]
class Campanhas extends Component
{
    // Form (draft).
    public bool $showForm = false;
    public ?int $editingId = null;
    public string $cName = '';
    public string $cMessage = '';
    public string $cAudienceType = 'tags'; // tags | coluna_kanban | contatos
    /** @var array<int,int> */
    public array $cTagIds = [];
    public ?int $cColumnId = null;
    /** @var array<int,int> */
    public array $cContactIds = [];
    public string $cContactSearch = '';
    public string $cStartAt = ''; // datetime-local opcional

    // Preview / aprovacao / cancelamento.
    public ?int $previewId = null;
    public ?int $confirmingApproveId = null;
    public ?int $confirmingCancelId = null;
    public ?int $confirmingUnapproveId = null;
    public ?int $confirmingPauseId = null;   // P-3: pausar/retomar
    public ?int $targetsOfId = null;         // P-3: modal de targets (status/motivo/hora)

    // ---- form (draft) ---------------------------------------------------------

    public function novo(): void
    {
        $this->reset(['editingId', 'cName', 'cMessage', 'cTagIds', 'cColumnId', 'cContactIds', 'cContactSearch', 'cStartAt']);
        $this->cAudienceType = 'tags';
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $c = $this->find($id);
        if (! $c || ! $c->isEditable()) {
            $this->dispatch('toast', message: 'Campanha aprovada e travada — des-aprove pra editar.', type: 'error');

            return;
        }

        $this->editingId = $c->id;
        $this->cName = (string) $c->name;
        $this->cMessage = (string) $c->message;
        $this->cAudienceType = (string) $c->audience_type;
        $cfg = (array) $c->audience_config;
        $this->cTagIds = array_map('intval', $cfg['tag_ids'] ?? []);
        $this->cColumnId = isset($cfg['column_id']) ? (int) $cfg['column_id'] : null;
        $this->cContactIds = array_map('intval', $cfg['contact_ids'] ?? []);
        $this->cContactSearch = '';
        $this->cStartAt = $c->start_at ? $c->start_at->copy()->setTimezone(config('app.display_timezone'))->format('Y-m-d\TH:i') : '';
        $this->resetValidation();
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetValidation();
    }

    public function save(SecretVault $vault): void
    {
        $this->validate([
            'cName' => 'required|string|max:120',
            'cMessage' => 'required|string|max:4000',
            'cAudienceType' => 'required|in:tags,coluna_kanban,contatos',
            'cStartAt' => 'nullable|date',
        ], [], ['cName' => 'nome', 'cMessage' => 'mensagem']);

        // {senha:}/segredo PROIBIDO em proativa — sem excecao (coerente com o guard).
        if ($vault->hasRef($this->cMessage)) {
            $this->addError('cMessage', 'Mensagem proativa NAO pode conter {senha:...}. Segredo so sai em resposta reativa com escopo de contatos especificos.');

            return;
        }

        // Config do publico por tipo (validada contra a conta).
        $config = match ($this->cAudienceType) {
            'tags' => ['tag_ids' => Tag::query()->whereIn('id', $this->cTagIds)->pluck('id')->all()],
            'coluna_kanban' => ['column_id' => $this->cColumnId],
            'contatos' => ['contact_ids' => Contact::query()->whereIn('id', $this->cContactIds)->pluck('id')->all()],
        };
        $vazio = match ($this->cAudienceType) {
            'tags' => empty($config['tag_ids']),
            'coluna_kanban' => empty($config['column_id']) || ! BoardColumn::query()->whereKey($config['column_id'])->whereHas('board', fn ($q) => $q->where('account_id', $this->accountId()))->exists(),
            'contatos' => empty($config['contact_ids']),
        };
        if ($vazio) {
            $this->addError('cAudienceType', 'Defina o publico (tags, coluna ou contatos).');

            return;
        }

        $startAt = $this->cStartAt !== ''
            ? \Illuminate\Support\Carbon::parse($this->cStartAt, config('app.display_timezone'))->utc()
            : null;

        $dados = [
            'name' => trim($this->cName),
            'message' => trim($this->cMessage),
            'audience_type' => $this->cAudienceType,
            'audience_config' => $config,
            'start_at' => $startAt,
        ];

        if ($this->editingId) {
            $c = $this->find($this->editingId);
            if (! $c || ! $c->isEditable()) {
                return;
            }
            // Editar volta pra draft (preview anterior deixa de valer).
            $c->update(array_merge($dados, ['status' => 'draft']));
        } else {
            ProactiveCampaign::create(array_merge($dados, ['status' => 'draft']));
        }

        $this->closeForm();
        $this->dispatch('toast', message: 'Campanha salva (rascunho). Nada dispara sem preview + aprovacao.');
    }

    // ---- preview -> aprovar (gate humano) ------------------------------------------

    /** Abre o preview: re-resolve o publico AGORA (retrato) e marca previewed. */
    public function openPreview(int $id): void
    {
        $c = $this->find($id);
        if (! $c || ! in_array($c->status, ['draft', 'previewed'], true)) {
            return;
        }
        if ($c->status === 'draft') {
            $c->update(['status' => 'previewed']);
        }
        $this->previewId = $id;
    }

    public function closePreview(): void
    {
        $this->previewId = null;
        $this->confirmingApproveId = null;
    }

    public function askApprove(int $id): void
    {
        $c = $this->find($id);
        if ($c && $c->status === 'previewed') {
            $this->confirmingApproveId = $id;
        }
    }

    public function cancelApprove(): void
    {
        $this->confirmingApproveId = null;
    }

    /**
     * APROVA: congela o SNAPSHOT — cria os targets pending da lista resolvida
     * agora (a mesma do preview aberto) e materializa a agenda (janela + jitter).
     * Mensagem/publico TRAVAM. Disparo mesmo: so na P-3, com o switch ligado.
     */
    public function approveConfirmed(AudienceResolver $resolver, AgendaBuilder $agenda): void
    {
        $c = $this->find($this->confirmingApproveId);
        $this->confirmingApproveId = null;
        if (! $c || $c->status !== 'previewed') {
            return;
        }

        $res = $resolver->resolve($this->accountId(), (string) $c->audience_type, (array) $c->audience_config);
        $eligiveis = $res['eligiveis'];
        if ($eligiveis->isEmpty()) {
            $this->dispatch('toast', message: 'Publico vazio (ninguem com opt-in) — nada a aprovar.', type: 'error');

            return;
        }

        $settings = \App\Models\AutoReplySetting::firstOrCreate(['account_id' => $this->accountId()]);
        $horarios = $agenda->build($settings, $c->start_at, $eligiveis->count());

        foreach ($eligiveis as $i => $contact) {
            CampaignTarget::create([
                'campaign_id' => $c->id,
                'contact_id' => $contact->id,
                'status' => 'pending',
                'scheduled_at' => $horarios[$i],
            ]);
        }

        $c->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => Auth::id(),
        ]);

        $this->closePreview();
        $this->dispatch('toast', message: 'Campanha APROVADA: snapshot congelado (' . $eligiveis->count() . ' contato(s) agendado(s)). O disparo em si chega na proxima fatia e exige o interruptor de proativas ligado.');
    }

    // ---- cancelar / des-aprovar ------------------------------------------------------

    public function askCancel(int $id): void
    {
        $this->confirmingCancelId = $id;
    }

    public function cancelCancel(): void
    {
        $this->confirmingCancelId = null;
    }

    public function cancelConfirmed(): void
    {
        $c = $this->find($this->confirmingCancelId);
        $this->confirmingCancelId = null;
        if (! $c || $c->status === 'cancelled') {
            return;
        }

        // Targets pendentes viram skipped/cancelada (trilha preservada).
        CampaignTarget::query()->where('campaign_id', $c->id)->where('status', 'pending')
            ->update(['status' => 'skipped', 'skip_reason' => 'cancelada']);
        $c->update(['status' => 'cancelled']);

        $this->dispatch('toast', message: 'Campanha cancelada. Nada sera enviado.');
    }

    public function askUnapprove(int $id): void
    {
        $this->confirmingUnapproveId = $id;
    }

    public function cancelUnapprove(): void
    {
        $this->confirmingUnapproveId = null;
    }

    /** Des-aprovar: SO sem target enviado (P-2: sempre). Apaga pendentes e libera edicao. */
    public function unapproveConfirmed(): void
    {
        $c = $this->find($this->confirmingUnapproveId);
        $this->confirmingUnapproveId = null;
        if (! $c || $c->status !== 'approved') {
            return;
        }

        $temEnviado = CampaignTarget::query()->where('campaign_id', $c->id)->where('status', 'sent')->exists();
        if ($temEnviado) {
            $this->dispatch('toast', message: 'Campanha ja enviou mensagens — nao da pra des-aprovar (cancele o restante).', type: 'error');

            return;
        }

        CampaignTarget::query()->where('campaign_id', $c->id)->delete();
        $c->update(['status' => 'draft', 'approved_at' => null, 'approved_by' => null]);

        $this->dispatch('toast', message: 'Aprovacao desfeita: agenda apagada, campanha editavel de novo.');
    }

    // ---- P-3: pausar / retomar / targets ------------------------------------------------

    public function askPause(int $id): void
    {
        $c = $this->find($id);
        if ($c && in_array($c->status, ['approved', 'running'], true)) {
            $this->confirmingPauseId = $id;
        }
    }

    public function cancelPause(): void
    {
        $this->confirmingPauseId = null;
    }

    public function pauseConfirmed(): void
    {
        $c = $this->find($this->confirmingPauseId);
        $this->confirmingPauseId = null;
        if ($c && in_array($c->status, ['approved', 'running'], true)) {
            $c->update(['status' => 'paused']);
            $this->dispatch('toast', message: 'Campanha pausada: os agendamentos pendentes param de ser processados.');
        }
    }

    /** Retomar: volta pra running e RECALCULA scheduled_at dos vencidos (janela+jitter). */
    public function resume(int $id, AgendaBuilder $agenda): void
    {
        $c = $this->find($id);
        if (! $c || $c->status !== 'paused') {
            return;
        }

        $vencidos = CampaignTarget::query()->where('campaign_id', $c->id)
            ->where('status', 'pending')->where('scheduled_at', '<=', now())->get();
        if ($vencidos->isNotEmpty()) {
            $settings = \App\Models\AutoReplySetting::firstOrCreate(['account_id' => $this->accountId()]);
            $novos = $agenda->build($settings, now(), $vencidos->count());
            foreach ($vencidos as $i => $t) {
                $t->update(['scheduled_at' => $novos[$i]]);
            }
        }

        $c->update(['status' => 'running']);
        $this->dispatch('toast', message: 'Campanha retomada (vencidos reagendados na janela).');
    }

    public function showTargets(int $id): void
    {
        if ($this->find($id)) {
            $this->targetsOfId = $id;
        }
    }

    public function closeTargets(): void
    {
        $this->targetsOfId = null;
    }

    // ---- consulta -----------------------------------------------------------------------

    private function find(?int $id): ?ProactiveCampaign
    {
        return $id ? ProactiveCampaign::query()->find($id) : null;
    }

    private function accountId(): int
    {
        return app(AccountContext::class)->id();
    }

    public function render(AudienceResolver $resolver, RuleResponder $responder)
    {
        $campanhas = ProactiveCampaign::query()->withCount([
            'targets as pendentes_count' => fn ($q) => $q->where('status', 'pending'),
            'targets as sent_count' => fn ($q) => $q->where('status', 'sent'),
            'targets as skipped_count' => fn ($q) => $q->where('status', 'skipped'),
            'targets as failed_count' => fn ($q) => $q->where('status', 'failed'),
            'targets as total_count',
        ])->latest('id')->get();

        // P-3: honestidade na tela — aprovadas aguardando o interruptor.
        $proativasLigadas = (bool) \App\Models\AutoReplySetting::firstOrCreate(['account_id' => $this->accountId()])->proactive_enabled;

        $targetsDe = $this->targetsOfId
            ? CampaignTarget::query()->where('campaign_id', $this->targetsOfId)
                ->with('contact:id,push_name,remote_jid')->orderBy('scheduled_at')->limit(200)->get()
            : collect();

        // Preview: retrato AGORA (re-resolve ao abrir; snapshot so na aprovacao).
        $preview = null;
        if ($this->previewId && ($c = $this->find($this->previewId))) {
            $res = $resolver->resolve($this->accountId(), (string) $c->audience_type, (array) $c->audience_config);
            $exemplo = $res['eligiveis']->first();
            $preview = [
                'campanha' => $c,
                'eligiveis' => $res['eligiveis'],
                'excluidos' => $res['excluidos'],
                'exemplo' => $exemplo
                    ? $responder->render((string) $c->message, ['nome' => $exemplo->push_name, 'now' => now()])
                    : null,
            ];
        }

        return view('livewire.campanhas', [
            'campanhas' => $campanhas,
            'preview' => $preview,
            'allTags' => Tag::query()->orderBy('name')->get(),
            'columns' => BoardColumn::query()->whereHas('board', fn ($q) => $q->where('account_id', $this->accountId())->where('is_default', true))->orderBy('position')->get(),
            'contactOptions' => $this->showForm && $this->cAudienceType === 'contatos'
                ? Contact::query()->when(trim($this->cContactSearch) !== '', function ($q) {
                    $b = '%' . trim($this->cContactSearch) . '%';
                    $q->where(fn ($w) => $w->where('push_name', 'like', $b)->orWhere('remote_jid', 'like', $b));
                })->orderByRaw('COALESCE(push_name, remote_jid)')->limit(200)->get(['id', 'push_name', 'remote_jid'])
                : collect(),
            'proativasLigadas' => $proativasLigadas,
            'targetsDe' => $targetsDe,
            'pausing' => $this->confirmingPauseId ? $this->find($this->confirmingPauseId) : null,
            'approving' => $this->confirmingApproveId ? $this->find($this->confirmingApproveId) : null,
            'cancelling' => $this->confirmingCancelId ? $this->find($this->confirmingCancelId) : null,
            'unapproving' => $this->confirmingUnapproveId ? $this->find($this->confirmingUnapproveId) : null,
        ]);
    }
}
