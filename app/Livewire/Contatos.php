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

    private const MODES = ['default', 'on', 'off'];

    public function setMode(int $id, string $mode): void
    {
        if (! in_array($mode, self::MODES, true)) {
            return;
        }

        Contact::query()
            ->where('id', $id)
            ->where('account_id', $this->accountId())
            ->update(['auto_reply_mode' => $mode]);
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

        Contact::query()
            ->where('id', $this->editingId)
            ->where('account_id', $this->accountId())
            ->update([
                'push_name' => $this->editName !== '' ? $this->editName : null,
                'notes' => $this->editNotes !== '' ? $this->editNotes : null,
            ]);

        $this->cancelEdit();
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

        return view('livewire.contatos', ['contacts' => $contacts]);
    }
}
