<?php

namespace App\Livewire;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Tag;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Contatos extends Component
{
    public string $search = '';

    // Prompt 19 — adicionar contato manualmente (form).
    public bool $showAdd = false;
    public string $newName = '';
    public string $newNumber = '';

    public ?int $editingId = null;
    public string $editName = '';
    public string $editNotes = '';
    public bool $editAiEnabled = false;
    public string $editAiMode = 'intencao';
    public bool $editProactiveOptIn = false; // P-1: opt-in explicito (trilha auditada)

    public ?int $confirmingMuteId = null;

    // T-1 — gerenciar tags (modal): renomear, cor, excluir com contagem de uso.
    // Fatia 12 — criar STANDALONE no proprio modal (sem passar por um contato).
    public bool $showTags = false;

    public string $newTagName = '';

    public string $newTagColor = 'zinc';
    /** @var array<int,string> id => nome */
    public array $tagNames = [];
    /** @var array<int,string> id => cor */
    public array $tagColors = [];
    public ?int $confirmingDeleteTagId = null;

    /** Aprovar (on) e default sao instantaneos. Silenciar (off) passa por confirmacao. */
    // ---- Prompt 19: adicionar contato manualmente -------------------------------

    public function openAdd(): void
    {
        $this->reset(['newName', 'newNumber']);
        $this->resetErrorBag(['newName', 'newNumber']);
        $this->showAdd = true;
    }

    public function cancelAdd(): void
    {
        $this->showAdd = false;
    }

    /**
     * Cria/adota contato manual (saved=true). Numero canonicalizado com o MESMO
     * helper do inbound/envio (BrWaId) — sem reimplementar. Dedup POR CONTA: se o
     * numero ja existe (qualquer variante do 9o digito), adota (nao duplica).
     */
    public function saveNew(): void
    {
        $this->validate([
            'newName' => 'required|string|max:120',
            'newNumber' => 'required|string',
        ], [], ['newName' => 'nome', 'newNumber' => 'numero']);

        $canonical = $this->canonicalizarNumero($this->newNumber);
        if ($canonical === null) {
            $this->addError('newNumber', 'Numero invalido — informe DDD + numero (ex.: 41 98765-4321).');

            return;
        }

        // Variantes do 9o digito (com/sem) — casa com o formato dos contatos auto.
        $variantes = array_values(array_unique(array_filter([
            $canonical,
            \App\Channels\CloudApi\BrWaId::comNonoDigito($canonical),
            \App\Channels\CloudApi\BrWaId::semNonoDigito($canonical),
        ])));
        $jids = array_map(fn ($d) => $d . '@s.whatsapp.net', $variantes);
        $nome = trim($this->newName);

        // Dedup/adocao SEMPRE escopada a conta ativa.
        $existente = Contact::query()->where('account_id', $this->accountId())
            ->whereIn('remote_jid', $jids)->first();

        if ($existente !== null) {
            $existente->saved = true;
            if ($nome !== '') {
                $existente->push_name = $nome; // nao sobrescreve com vazio
            }
            $existente->save();
            $this->dispatch('toast', message: 'Contato adicionado a lista.');
        } else {
            Contact::create([
                'account_id' => $this->accountId(),
                'remote_jid' => $canonical . '@s.whatsapp.net',
                'push_name' => $nome !== '' ? $nome : null,
                'saved' => true,
                'auto_reply_mode' => 'default',
            ]);
            $this->dispatch('toast', message: 'Contato adicionado.');
        }

        $this->showAdd = false;
        $this->reset(['newName', 'newNumber']);
    }

    /**
     * Digitos canonicos (com DDI) do numero, ou null se nao for canonicalizavel.
     * Reusa BrWaId::paraEnvio (celular BR sempre COM o 9 — mesma regra do envio,
     * casa com Evolution e com canonicalJid do Cloud).
     */
    private function canonicalizarNumero(string $input): ?string
    {
        $d = preg_replace('/\D+/', '', $input);

        // BR local sem DDI (10-11 digitos): prefixa 55.
        if (! str_starts_with($d, '55') && (strlen($d) === 10 || strlen($d) === 11)) {
            $d = '55' . $d;
        }

        // Valido = BR full: 12 (fixo) ou 13 (celular com 9). Fora disso: lixo.
        if (strlen($d) < 12 || strlen($d) > 13) {
            return null;
        }

        return \App\Channels\CloudApi\BrWaId::paraEnvio($d);
    }

    public function setMode(int $id, string $mode): void
    {
        if (! in_array($mode, ['default', 'on'], true)) {
            return;
        }

        Contact::query()->where('id', $id)->where('account_id', $this->accountId())
            ->update(['auto_reply_mode' => $mode]);

        $this->dispatch('toast', message: $mode === 'on' ? 'Contato aprovado.' : 'Contato em default.');
    }

    public function confirmMute(int $id): void
    {
        $this->confirmingMuteId = $id;
    }

    public function cancelMute(): void
    {
        $this->confirmingMuteId = null;
    }

    public function muteConfirmed(): void
    {
        if ($this->confirmingMuteId) {
            Contact::query()->where('id', $this->confirmingMuteId)->where('account_id', $this->accountId())
                ->update(['auto_reply_mode' => 'off']);
            $this->dispatch('toast', message: 'Contato silenciado.');
        }
        $this->confirmingMuteId = null;
    }

    public function startEdit(int $id): void
    {
        $contact = Contact::query()->where('account_id', $this->accountId())->findOrFail($id);
        $this->editingId = $contact->id;
        $this->editName = (string) $contact->push_name;
        $this->editNotes = (string) $contact->notes;
        $this->editAiEnabled = (bool) $contact->ai_enabled;
        $this->editAiMode = (string) ($contact->ai_mode ?: 'intencao');
        $this->editProactiveOptIn = (bool) $contact->proactive_opt_in;
    }

    public function saveEdit(): void
    {
        if (! $this->editingId) {
            return;
        }

        $aiMode = in_array($this->editAiMode, ['rules_only', 'intencao', 'conhecimento', 'aprovacao'], true)
            ? $this->editAiMode
            : 'intencao';

        $contato = Contact::query()->where('account_id', $this->accountId())->find($this->editingId);
        $optInAnterior = (bool) $contato?->proactive_opt_in;

        Contact::query()->where('id', $this->editingId)->where('account_id', $this->accountId())
            ->update([
                'push_name' => $this->editName !== '' ? $this->editName : null,
                'notes' => $this->editNotes !== '' ? $this->editNotes : null,
                'ai_enabled' => $this->editAiEnabled,
                'ai_mode' => $aiMode,
                'proactive_opt_in' => $this->editProactiveOptIn,
            ]);

        // P-1 — trilha de consentimento AUDITAVEL: toda mudanca de opt-in registra
        // grant/revoke com origem manual (nunca apagada; LGPD).
        if ($contato && $optInAnterior !== $this->editProactiveOptIn) {
            \App\Models\ProactiveConsent::create([
                'account_id' => $this->accountId(),
                'contact_id' => $contato->id,
                'action' => $this->editProactiveOptIn ? 'grant' : 'revoke',
                'origin' => 'manual',
            ]);

            // P-3: revogacao manual tambem pula o contato em todas as campanhas.
            if (! $this->editProactiveOptIn) {
                \App\Models\CampaignTarget::skipAllPendingFor($this->accountId(), $contato->id, 'opt_out_revogado');
            }
        }

        $this->cancelEdit();
        $this->dispatch('toast', message: 'Contato salvo.');
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->editName = '';
        $this->editNotes = '';
        $this->editAiEnabled = false;
        $this->editAiMode = 'intencao';
    }

    // ---- T-1: gerenciar tags -------------------------------------------------

    public function openTags(): void
    {
        $this->tagNames = Tag::query()->orderBy('name')->pluck('name', 'id')->all();
        $this->tagColors = Tag::query()->pluck('color', 'id')->all();
        $this->newTagName = '';
        $this->newTagColor = 'zinc';
        $this->resetValidation();
        $this->showTags = true;
    }

    /**
     * Fatia 12 — cria a tag SEM contato envolvido (o caminho principal pedido
     * pelo dono; a criacao inline do painel do contato permanece como atalho).
     * MESMA disciplina da criacao inline (ContactTags::addTag) e do renomear
     * (saveTags): nome obrigatorio ate 40, unico POR CONTA via LOWER(name)
     * (case-insensitive; no MySQL a collation ci tambem casa sem acento), cor da
     * paleta. Escopo: BelongsToAccount preenche account_id da conta ativa.
     * A tag nasce sem pivo — origem ('manual') e rastreada no ATTACH, como hoje.
     */
    public function createTag(): void
    {
        $nome = trim($this->newTagName);
        if ($nome === '' || mb_strlen($nome) > 40) {
            $this->addError('newTagName', 'Informe um nome de ate 40 caracteres.');

            return;
        }
        if (Tag::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($nome, 'UTF-8')])->exists()) {
            $this->addError('newTagName', 'Ja existe tag com esse nome.');

            return;
        }
        $cor = in_array($this->newTagColor, Tag::COLORS, true) ? $this->newTagColor : 'zinc';

        $tag = Tag::create(['name' => $nome, 'color' => $cor]);

        // Entra na lista do modal (mapas de edicao) sem fechar nada.
        $this->tagNames = Tag::query()->orderBy('name')->pluck('name', 'id')->all();
        $this->tagColors = Tag::query()->pluck('color', 'id')->all();
        $this->newTagName = '';
        $this->newTagColor = 'zinc';
        $this->resetValidation();
        $this->dispatch('toast', message: 'Tag "' . $tag->name . '" criada. Atribua a contatos quando quiser.');
    }

    public function closeTags(): void
    {
        $this->showTags = false;
        $this->confirmingDeleteTagId = null;
        $this->resetValidation();
    }

    public function saveTags(): void
    {
        foreach (Tag::query()->get() as $tag) {
            $nome = trim((string) ($this->tagNames[$tag->id] ?? ''));
            $cor = in_array($this->tagColors[$tag->id] ?? '', Tag::COLORS, true) ? $this->tagColors[$tag->id] : $tag->color;
            if ($nome === '') {
                $this->addError('tagNames.' . $tag->id, 'Nome nao pode ficar vazio.');

                return;
            }
            // UNIQUE por conta: renomear pra nome ja usado e bloqueado.
            $duplicada = Tag::query()->where('id', '!=', $tag->id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($nome, 'UTF-8')])->exists();
            if ($duplicada) {
                $this->addError('tagNames.' . $tag->id, 'Ja existe tag com esse nome.');

                return;
            }
            if ($nome !== $tag->name || $cor !== $tag->color) {
                $tag->update(['name' => $nome, 'color' => $cor]);
            }
        }

        $this->closeTags();
        $this->dispatch('toast', message: 'Tags salvas.');
    }

    public function confirmDeleteTag(int $id): void
    {
        $this->confirmingDeleteTagId = $id;
    }

    public function cancelDeleteTag(): void
    {
        $this->confirmingDeleteTagId = null;
    }

    /**
     * Excluir tag: remove os pivos (contatos) por cascade. Regras/fluxos que usam a
     * tag como ESCOPO ficam sem alcance ate ajuste (aviso no modal); board_rules de
     * tag ficam inertes (tag_id null). Confirmacao obrigatoria com contagem de uso.
     */
    public function deleteTagConfirmed(): void
    {
        if ($this->confirmingDeleteTagId) {
            Tag::query()->where('id', $this->confirmingDeleteTagId)->delete();
            $this->tagNames = Tag::query()->orderBy('name')->pluck('name', 'id')->all();
            $this->tagColors = Tag::query()->pluck('color', 'id')->all();
            $this->dispatch('toast', message: 'Tag excluida.');
        }
        $this->confirmingDeleteTagId = null;
    }

    /** Uso da tag (pro modal de exclusao). */
    public function tagUsage(int $tagId): array
    {
        return [
            'contatos' => \Illuminate\Support\Facades\DB::table('contact_tag')->where('tag_id', $tagId)->count(),
            'regras' => \Illuminate\Support\Facades\DB::table('rule_tag')->where('tag_id', $tagId)->count(),
            'fluxos' => \Illuminate\Support\Facades\DB::table('flow_tag')->where('tag_id', $tagId)->count(),
            'kanban' => \App\Models\BoardRule::query()->where('tag_id', $tagId)->count(),
        ];
    }

    private function accountId(): int
    {
        // MT-0: conta do CONTEXTO (fase 1 = conta unica, fallback centralizado).
        return app(\App\Tenancy\AccountContext::class)->id();
    }

    public function render()
    {
        $contacts = Contact::query()->with('tags')
            ->where('account_id', $this->accountId())
            // Prompt 19: "meus contatos" = criados do zero OU nomeados/salvos (saved=true).
            // Auto nunca-tocados (saved=false) NAO aparecem AQUI, mas seguem vivos no
            // resto do sistema (Kanban/Conversas/Painel/reativo).
            ->where('saved', true)
            ->when($this->search !== '', function ($q) {
                $q->where(function ($w) {
                    $w->where('remote_jid', 'like', '%' . $this->search . '%')
                        ->orWhere('push_name', 'like', '%' . $this->search . '%');
                });
            })
            ->orderByRaw('COALESCE(push_name, remote_jid)')
            ->limit(300)
            ->get();

        $scoped = fn (?int $id) => $id
            ? Contact::query()->where('account_id', $this->accountId())->find($id)
            : null;

        return view('livewire.contatos', [
            'contacts' => $contacts,
            'editing' => $scoped($this->editingId),
            'muting' => $scoped($this->confirmingMuteId),
            // Fatia 12: contagem de uso por tag (withCount — sem N+1) pro modal.
            'tagList' => $this->showTags ? Tag::query()->withCount('contacts')->orderBy('name')->get() : collect(),
            'deletingTag' => $this->confirmingDeleteTagId ? Tag::query()->find($this->confirmingDeleteTagId) : null,
        ]);
    }
}
