<?php

namespace App\Livewire;

use App\Models\Account;
use App\Models\AutoReplyLog;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\IncomingMessage;
use App\Whatsapp\AutoReply\Sender;
use App\Whatsapp\MessagePreview;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Conversas extends Component
{
    public ?string $selectedJid = null;
    public string $body = '';
    public ?string $sendStatus = null;
    public ?string $confirmingMuteJid = null;
    public string $search = '';

    // S4 — painel de info do contato.
    public bool $showContactPanel = false;
    public string $panelName = '';
    public string $panelNotes = '';

    public function select(string $jid): void
    {
        $this->selectedJid = $jid;
        $this->sendStatus = null;
        $this->showContactPanel = false;
    }

    public function approveJid(string $jid): void
    {
        Contact::updateOrCreate(
            ['account_id' => $this->accountId(), 'remote_jid' => $jid],
            ['auto_reply_mode' => 'on'],
        );
        $this->dispatch('toast', message: 'Contato aprovado: o robo passa a responder este contato automaticamente.');
    }

    /** Define on/default direto (off passa por confirmacao via confirmMute). */
    public function setSelectedMode(string $mode): void
    {
        if (! $this->selectedJid || ! in_array($mode, ['default', 'on'], true)) {
            return;
        }

        Contact::updateOrCreate(
            ['account_id' => $this->accountId(), 'remote_jid' => $this->selectedJid],
            ['auto_reply_mode' => $mode],
        );

        $this->dispatch('toast', message: $mode === 'on'
            ? 'Robo passa a responder este contato (on).'
            : 'Contato volta a seguir a politica (default).');
    }

    // ---- S4: painel de info do contato -------------------------------------

    public function openContactPanel(): void
    {
        if (! $this->selectedJid || str_ends_with($this->selectedJid, '@g.us')) {
            return;
        }

        $contact = $this->selectedContact();
        $this->panelName = (string) ($contact?->push_name ?? '');
        $this->panelNotes = (string) ($contact?->notes ?? '');
        $this->showContactPanel = true;
    }

    public function closeContactPanel(): void
    {
        $this->showContactPanel = false;
    }

    /** Salva nome/notas e marca o contato como "salvo" (adicionado pelo usuario). */
    public function saveContact(): void
    {
        if (! $this->selectedJid) {
            return;
        }

        Contact::updateOrCreate(
            ['account_id' => $this->accountId(), 'remote_jid' => $this->selectedJid],
            [
                'push_name' => trim($this->panelName) !== '' ? trim($this->panelName) : null,
                'notes' => trim($this->panelNotes) !== '' ? trim($this->panelNotes) : null,
                'saved' => true,
            ],
        );

        $this->dispatch('toast', message: 'Contato salvo.');
    }

    private function selectedContact(): ?Contact
    {
        return $this->selectedJid
            ? Contact::query()->where('account_id', $this->accountId())->where('remote_jid', $this->selectedJid)->first()
            : null;
    }

    /**
     * Midias recentes da conversa (S4). So a LISTA/referencia (tipo + hora).
     * O render real da imagem fica pra fatia futura (baixar/guardar midia da
     * Evolution -> disco/LGPD).
     */
    private function recentMedia(): array
    {
        if (! $this->selectedJid) {
            return [];
        }

        $tipos = ['imageMessage', 'videoMessage', 'documentMessage', 'audioMessage', 'stickerMessage'];

        return IncomingMessage::query()
            ->where('account_id', $this->accountId())
            ->where('remote_jid', $this->selectedJid)
            ->whereIn('type', $tipos)
            ->orderByDesc('received_at')
            ->limit(20)
            ->get()
            ->map(fn ($m) => [
                'label' => match ($m->type) {
                    'imageMessage' => 'Imagem',
                    'videoMessage' => 'Video',
                    'documentMessage' => 'Documento',
                    'audioMessage' => 'Audio',
                    'stickerMessage' => 'Figurinha',
                    default => $m->type,
                },
                'icon' => match ($m->type) {
                    'imageMessage' => 'photo',
                    'videoMessage' => 'video-camera',
                    'documentMessage' => 'document',
                    'audioMessage' => 'musical-note',
                    default => 'paper-clip',
                },
                'at' => $m->received_at,
            ])
            ->all();
    }

    public function confirmMute(string $jid): void
    {
        $this->confirmingMuteJid = $jid;
    }

    public function cancelMute(): void
    {
        $this->confirmingMuteJid = null;
    }

    public function muteConfirmed(): void
    {
        if ($this->confirmingMuteJid) {
            Contact::updateOrCreate(
                ['account_id' => $this->accountId(), 'remote_jid' => $this->confirmingMuteJid],
                ['auto_reply_mode' => 'off'],
            );
            $this->dispatch('toast', message: 'Contato silenciado.');
        }
        $this->confirmingMuteJid = null;
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
        if ($log->status === 'sent') {
            $this->sendStatus = null;
            $this->dispatch('toast', message: 'Mensagem enviada.');
        } else {
            $this->sendStatus = $log->status === 'blocked'
                ? 'Bloqueado por freio: ' . $log->motivo
                : 'Falha no envio.';
        }
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
                    'preview' => MessagePreview::for($m->type, $m->text, (array) $m->raw_payload),
                ];
            }
        }

        foreach (AutoReplyLog::query()->where('account_id', $account)->where('status', 'sent')->orderByDesc('sent_at')->limit(1000)->get() as $l) {
            $at = $l->sent_at;
            if ($at && (! isset($rows[$l->remote_jid]) || $at->greaterThan($rows[$l->remote_jid]['at']))) {
                $rows[$l->remote_jid] = [
                    'jid' => $l->remote_jid,
                    'at' => $at,
                    'preview' => MessagePreview::plain($l->response_text),
                ];
            }
        }

        $busca = $this->normalizeSearch($this->search);

        return collect($rows)->map(function ($r) use ($contacts) {
            $c = $contacts->get($r['jid']);
            $r['name'] = $c?->push_name ?: $this->numberFromJid($r['jid']);
            $r['mode'] = $c?->auto_reply_mode ?? 'default';
            $r['is_group'] = str_ends_with($r['jid'], '@g.us');
            $r['time_label'] = $r['at'] ? $this->relativeTime($r['at']) : '';

            return $r;
        })
            ->when($busca !== '', fn ($col) => $col->filter(
                fn ($r) => str_contains($this->normalizeSearch($r['name']), $busca)
                    || str_contains($this->normalizeSearch($this->numberFromJid($r['jid'])), $busca)
            ))
            ->sortByDesc('at')
            ->values();
    }

    private function normalizeSearch(string $value): string
    {
        return mb_strtolower(trim($value), 'UTF-8');
    }

    /** Hora relativa estilo WhatsApp: hoje -> H:i; ontem -> "Ontem"; antigo -> d/m. */
    private function relativeTime(\Illuminate\Support\Carbon $at): string
    {
        $local = $at->paraExibicao();
        $hoje = now()->paraExibicao()->startOfDay();
        $dia = $local->copy()->startOfDay();
        // Carbon 3: diffInDays e SINALIZADO. Datas passadas dao negativo -> abs().
        $diff = (int) abs($hoje->diffInDays($dia));

        return match (true) {
            $diff === 0 => $local->format('H:i'),
            $diff === 1 => 'Ontem',
            default => $local->format('d/m'),
        };
    }

    /** Rotulo do separador de data na thread. */
    private function dateSeparator(\Illuminate\Support\Carbon $at): string
    {
        $local = $at->paraExibicao();
        $hoje = now()->paraExibicao()->startOfDay();
        $dia = $local->copy()->startOfDay();
        // Carbon 3: diffInDays e SINALIZADO. Datas passadas dao negativo -> abs().
        $diff = (int) abs($hoje->diffInDays($dia));

        return match (true) {
            $diff === 0 => 'Hoje',
            $diff === 1 => 'Ontem',
            default => $local->format('d/m/Y'),
        };
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
            if ($m->from_me && in_array($m->evolution_message_id, $providerIds, true)) {
                continue;
            }
            $items[] = [
                'at' => $m->received_at,
                'preview' => MessagePreview::for($m->type, $m->text, (array) $m->raw_payload),
                'kind' => $m->from_me ? 'out_phone' : 'in',
            ];
        }

        foreach ($logs as $l) {
            $items[] = [
                'at' => $l->sent_at ?? $l->created_at,
                'preview' => MessagePreview::plain($l->response_text),
                'kind' => $l->mode === 'auto' ? 'out_bot' : 'out_manual',
            ];
        }

        usort($items, fn ($a, $b) => ($a['at'] <=> $b['at']));

        // S6: separadores de data ("Hoje"/"Ontem"/data) + agrupamento de mensagens
        // seguidas do mesmo lado (esconde repeticao, aperta o espacamento).
        $prevDate = null;
        $prevSide = null;
        foreach ($items as $i => &$item) {
            $side = $item['kind'] === 'in' ? 'in' : 'out';
            $dateKey = $item['at'] ? $item['at']->paraExibicao()->format('Y-m-d') : null;

            $item['side'] = $side;
            $item['separator'] = ($dateKey !== $prevDate && $item['at']) ? $this->dateSeparator($item['at']) : null;
            $item['grouped'] = ($side === $prevSide && $item['separator'] === null);

            $prevDate = $dateKey;
            $prevSide = $side;
        }
        unset($item);

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
            'mutingName' => $this->confirmingMuteJid ? ($selectedContact?->push_name ?: $this->numberFromJid($this->confirmingMuteJid)) : null,
            'recentMedia' => $this->showContactPanel ? $this->recentMedia() : [],
        ]);
    }
}
