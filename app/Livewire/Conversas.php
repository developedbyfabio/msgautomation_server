<?php

namespace App\Livewire;

use App\Models\Account;
use App\Models\AutoReplyLog;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\IncomingMessage;
use App\Whatsapp\AutoReply\Sender;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Conversas extends Component
{
    public ?string $selectedJid = null;
    public string $body = '';
    public ?string $sendStatus = null;

    public function select(string $jid): void
    {
        $this->selectedJid = $jid;
        $this->sendStatus = null;
    }

    public function approveContact(): void
    {
        $this->setSelectedMode('on');
    }

    public function muteContact(): void
    {
        $this->setSelectedMode('off');
    }

    private function setSelectedMode(string $mode): void
    {
        if (! $this->selectedJid) {
            return;
        }

        Contact::updateOrCreate(
            ['account_id' => $this->accountId(), 'remote_jid' => $this->selectedJid],
            ['auto_reply_mode' => $mode],
        );
    }

    /** Envio MANUAL (R1): respeita tetos protetivos, ignora kill switch. Envia de verdade. */
    public function sendManual(Sender $sender): void
    {
        $body = trim($this->body);
        if ($body === '' || ! $this->selectedJid) {
            return;
        }

        $channel = $this->channel();
        if (! $channel) {
            $this->sendStatus = 'Sem canal configurado.';

            return;
        }

        $log = $sender->send('manual', $channel, $this->selectedJid, $body);

        $this->body = '';
        $this->sendStatus = match ($log->status) {
            'sent' => null,
            'blocked' => 'Bloqueado por freio: ' . $log->motivo,
            default => 'Falha no envio.',
        };
    }

    private function channel(): ?Channel
    {
        return Channel::query()->where('account_id', $this->accountId())->oldest('id')->first();
    }

    private function accountId(): int
    {
        return (int) (Account::query()->oldest('id')->value('id') ?? 0);
    }

    private function numberFromJid(string $jid): string
    {
        return Str::before($jid, '@');
    }

    private function conversations()
    {
        $account = $this->accountId();
        $contacts = Contact::query()->where('account_id', $account)->get()->keyBy('remote_jid');

        $rows = [];

        foreach (IncomingMessage::query()->where('account_id', $account)->orderByDesc('received_at')->limit(1000)->get() as $m) {
            $at = $m->received_at;
            if (! isset($rows[$m->remote_jid]) || ($at && $at->greaterThan($rows[$m->remote_jid]['at']))) {
                $rows[$m->remote_jid] = [
                    'jid' => $m->remote_jid,
                    'at' => $at,
                    'text' => $m->text ?: '[' . $m->type . ']',
                    'dir' => $m->from_me ? 'out' : 'in',
                ];
            }
        }

        foreach (AutoReplyLog::query()->where('account_id', $account)->where('status', 'sent')->orderByDesc('sent_at')->limit(1000)->get() as $l) {
            $at = $l->sent_at;
            if ($at && (! isset($rows[$l->remote_jid]) || $at->greaterThan($rows[$l->remote_jid]['at']))) {
                $rows[$l->remote_jid] = [
                    'jid' => $l->remote_jid,
                    'at' => $at,
                    'text' => $l->response_text,
                    'dir' => 'out',
                ];
            }
        }

        return collect($rows)->map(function ($r) use ($contacts) {
            $c = $contacts->get($r['jid']);
            $r['name'] = $c?->push_name ?: $this->numberFromJid($r['jid']);
            $r['mode'] = $c?->auto_reply_mode ?? 'default';
            $r['is_group'] = str_ends_with($r['jid'], '@g.us');

            return $r;
        })->sortByDesc('at')->values();
    }

    private function thread(): array
    {
        if (! $this->selectedJid) {
            return [];
        }

        $account = $this->accountId();

        $logs = AutoReplyLog::query()
            ->where('account_id', $account)
            ->where('remote_jid', $this->selectedJid)
            ->where('status', 'sent')
            ->get();

        $providerIds = $logs->pluck('provider_message_id')->filter()->all();

        $items = [];

        foreach (IncomingMessage::query()->where('account_id', $account)->where('remote_jid', $this->selectedJid)->orderBy('received_at')->limit(500)->get() as $m) {
            // Dedup: mensagem propria (fromMe) que e o eco de um envio do app ja aparece como log.
            if ($m->from_me && in_array($m->evolution_message_id, $providerIds, true)) {
                continue;
            }
            $items[] = [
                'at' => $m->received_at,
                'text' => $m->text ?: '[' . $m->type . ']',
                'kind' => $m->from_me ? 'out_phone' : 'in',
            ];
        }

        foreach ($logs as $l) {
            $items[] = [
                'at' => $l->sent_at ?? $l->created_at,
                'text' => $l->response_text,
                'kind' => $l->mode === 'auto' ? 'out_bot' : 'out_manual',
            ];
        }

        usort($items, fn ($a, $b) => ($a['at'] <=> $b['at']));

        return $items;
    }

    public function render()
    {
        $selectedContact = $this->selectedJid
            ? Contact::query()->where('account_id', $this->accountId())->where('remote_jid', $this->selectedJid)->first()
            : null;

        return view('livewire.conversas', [
            'conversations' => $this->conversations(),
            'thread' => $this->thread(),
            'selectedContact' => $selectedContact,
            'isGroup' => $this->selectedJid ? str_ends_with($this->selectedJid, '@g.us') : false,
        ]);
    }
}
