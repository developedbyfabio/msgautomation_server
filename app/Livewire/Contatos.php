<?php

namespace App\Livewire;

use App\Models\Account;
use App\Models\Contact;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Contatos extends Component
{
    public string $search = '';

    public ?int $editingId = null;
    public string $editName = '';
    public string $editNotes = '';

    public ?int $confirmingMuteId = null;

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
    }

    public function saveEdit(): void
    {
        if (! $this->editingId) {
            return;
        }

        Contact::query()->where('id', $this->editingId)->where('account_id', $this->accountId())
            ->update([
                'push_name' => $this->editName !== '' ? $this->editName : null,
                'notes' => $this->editNotes !== '' ? $this->editNotes : null,
            ]);

        $this->cancelEdit();
        $this->dispatch('toast', message: 'Contato salvo.');
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->editName = '';
        $this->editNotes = '';
    }

    private function accountId(): int
    {
        return (int) (Account::query()->oldest('id')->value('id') ?? 0);
    }

    public function render()
    {
        $contacts = Contact::query()
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
        ]);
    }
}
