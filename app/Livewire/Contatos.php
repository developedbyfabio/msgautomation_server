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

    public ?int $editingId = null;
    public string $editName = '';
    public string $editNotes = '';
    public bool $editAiEnabled = false;
    public string $editAiMode = 'intencao';
    public bool $editProactiveOptIn = false; // P-1: opt-in explicito (trilha auditada)

    public ?int $confirmingMuteId = null;

    // T-1 — gerenciar tags (modal): renomear, cor, excluir com contagem de uso.
    public bool $showTags = false;
    /** @var array<int,string> id => nome */
    public array $tagNames = [];
    /** @var array<int,string> id => cor */
    public array $tagColors = [];
    public ?int $confirmingDeleteTagId = null;

    /** Aprovar (on) e default sao instantaneos. Silenciar (off) passa por confirmacao. */
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
        $this->resetValidation();
        $this->showTags = true;
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
            'tagList' => $this->showTags ? Tag::query()->orderBy('name')->get() : collect(),
            'deletingTag' => $this->confirmingDeleteTagId ? Tag::query()->find($this->confirmingDeleteTagId) : null,
        ]);
    }
}
