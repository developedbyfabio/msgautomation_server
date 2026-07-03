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
use Livewire\WithFileUploads;

#[Layout('components.layouts.app')]
class Conversas extends Component
{
    use WithFileUploads;

    public ?string $selectedJid = null;
    public string $body = '';

    /** Prompts 04/05 — anexo (imagem ou documento; upload temporario do Livewire; preview antes de enviar). */
    public $anexo = null;
    public ?string $sendStatus = null;
    public ?string $confirmingMuteJid = null;
    public string $search = '';

    // S4 — painel de info do contato.
    public bool $showContactPanel = false;
    public string $panelName = '';
    public string $panelNotes = '';

    /** K-2: o Kanban linka /conversas?jid=... pra abrir a conversa direto. */
    public function mount(): void
    {
        $jid = (string) request()->query('jid', '');
        if ($jid !== '') {
            $this->selectedJid = $jid;
        }
    }

    public function select(string $jid): void
    {
        $this->selectedJid = $jid;
        $this->sendStatus = null;
        $this->showContactPanel = false;
    }

    /** S4 — atualiza o nome do grupo sob demanda (re-busca na Evolution agora). */
    public function atualizarNomeGrupo(\App\Whatsapp\Groups\GroupNameResolver $resolver): void
    {
        if (! $this->selectedJid || ! str_ends_with($this->selectedJid, '@g.us')) {
            return;
        }

        $nome = $resolver->resolveNow($this->accountId(), $this->selectedJid);

        $this->dispatch('toast',
            message: $nome ? 'Nome do grupo atualizado: ' . $nome : 'Nao foi possivel obter o nome do grupo agora.',
            type: $nome ? 'success' : 'error',
        );
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

    // ---- Prompts 04/05: anexos (imagem e documento) -------------------------

    /** Mimes de documento aceitos (obrigatorio: PDF; docx/xlsx saem de graca na mesma mecanica). */
    private const MIMES_DOCUMENTO = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /** 'image' | 'document' (pelo mime real do upload). */
    private function anexoKind(): string
    {
        return str_starts_with((string) ($this->anexo?->getMimeType() ?? ''), 'image/') ? 'image' : 'document';
    }

    /**
     * Regras por tipo: imagem jpeg/png/webp ate 5 MB (limite de imagem da Meta);
     * documento PDF/docx/xlsx ate 10 MB (folga sob os limites dos canais e do
     * upload do Livewire — o teto da Meta pra documento e bem maior).
     */
    private function regrasAnexo(): array
    {
        return $this->anexoKind() === 'image'
            ? ['anexo' => 'image|mimes:jpg,jpeg,png,webp|max:5120']
            : ['anexo' => 'file|mimetypes:' . implode(',', self::MIMES_DOCUMENTO) . '|max:10240'];
    }

    private const MENSAGENS_ANEXO = [
        'anexo.image' => 'O arquivo escolhido nao e uma imagem valida.',
        'anexo.mimes' => 'Imagem em tipo nao aceito — use jpg, jpeg, png ou webp.',
        'anexo.mimetypes' => 'Documento em tipo nao aceito — use PDF (ou docx/xlsx).',
        'anexo.max' => 'Arquivo acima do limite (5 MB pra imagem, 10 MB pra documento).',
    ];

    /** Valida na hora do anexo: tipo/tamanho invalido e recusado ANTES do preview. */
    public function updatedAnexo(): void
    {
        $this->resetErrorBag('anexo');
        try {
            $this->validate($this->regrasAnexo(), self::MENSAGENS_ANEXO);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->anexo = null;
            throw $e;
        }
    }

    public function cancelarAnexo(): void
    {
        $this->anexo = null;
        $this->resetErrorBag('anexo');
    }

    /**
     * Envio manual de ANEXO (imagem/documento): mesma disciplina do texto
     * (Sender modo manual — respeita tetos, ignora kill switch, janela de 24h
     * no cloud). O texto digitado vira LEGENDA. Midia no disco privado, por conta.
     */
    private function sendAnexo(Sender $sender): void
    {
        if (! $this->anexo || ! $this->selectedJid) {
            return;
        }
        $this->validate($this->regrasAnexo(), self::MENSAGENS_ANEXO);

        $channel = $this->channel();
        if (! $channel) {
            $this->sendStatus = 'Sem canal configurado.';

            return;
        }

        $kind = $this->anexoKind();

        // Path POR CONTA (isolamento): media/{account}/{numero}/{uuid}.{ext}
        $dir = 'media/' . $this->accountId() . '/' . $this->numberFromJid($this->selectedJid);
        $nome = (string) Str::uuid() . '.' . strtolower($this->anexo->getClientOriginalExtension());
        $path = $this->anexo->storeAs($dir, $nome, 'local');

        $log = $sender->send('manual', $channel, $this->selectedJid, trim($this->body), media: [
            'kind' => $kind,
            'path' => $path,
            'mime' => (string) $this->anexo->getMimeType(),
            'name' => (string) $this->anexo->getClientOriginalName(),
        ]);

        $this->anexo = null;
        if ($log->status === 'sent') {
            $this->body = '';
            $this->sendStatus = null;
            $this->dispatch('toast', message: $kind === 'image' ? 'Imagem enviada.' : 'Documento enviado.');
        } else {
            $this->sendStatus = $log->status === 'blocked'
                ? 'Bloqueado por freio: ' . $log->motivo
                : 'Falha no envio do anexo.';
        }
    }

    /** Envio MANUAL (R1): respeita tetos protetivos, ignora kill switch. Envia de verdade. */
    public function sendManual(Sender $sender): void
    {
        // Prompts 04/05: com anexo pendente, o Enviar manda o ANEXO (texto = legenda).
        if ($this->anexo) {
            $this->sendAnexo($sender);

            return;
        }

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
        // MT-0: conta do CONTEXTO (fase 1 = conta unica, fallback centralizado).
        return app(\App\Tenancy\AccountContext::class)->id();
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

        $resolver = app(\App\Whatsapp\Groups\GroupNameResolver::class);

        return collect($rows)->map(function ($r) use ($contacts, $resolver, $account) {
            $c = $contacts->get($r['jid']);
            $isGroup = str_ends_with($r['jid'], '@g.us');
            if ($isGroup) {
                // S4: nome do grupo (cache em DB); dispara resolucao em background se faltar.
                $resolver->ensure($account, $r['jid']);
                $r['name'] = $resolver->nameFor($account, $r['jid']) ?: $this->numberFromJid($r['jid']);
            } else {
                $r['name'] = $c?->push_name ?: $this->numberFromJid($r['jid']);
            }
            $r['mode'] = $c?->auto_reply_mode ?? 'default';
            $r['is_group'] = $isGroup;
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
                // Prompts 04/05: anexo enviado renderiza na bolha (rota autenticada+escopada).
                'media' => $l->media_path ? route('media.show', $l->id) : null,
                'media_kind' => str_starts_with((string) $l->media_mime, 'image/') ? 'image' : 'document',
                'media_name' => $l->media_name,
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

        $isGroup = $this->selectedJid ? str_ends_with($this->selectedJid, '@g.us') : false;

        // Nome do cabecalho: grupo -> subject (cache); contato -> push_name; senao numero.
        $selectedName = null;
        if ($this->selectedJid) {
            $selectedName = $isGroup
                ? (app(\App\Whatsapp\Groups\GroupNameResolver::class)->nameFor($this->accountId(), $this->selectedJid) ?: $this->numberFromJid($this->selectedJid))
                : ($selectedContact?->push_name ?: $this->numberFromJid($this->selectedJid));
        }

        return view('livewire.conversas', [
            'conversations' => $this->conversations(),
            'thread' => $this->thread(),
            'selectedContact' => $selectedContact,
            'selectedName' => $selectedName,
            'isGroup' => $isGroup,
            'mutingName' => $this->confirmingMuteJid ? ($selectedContact?->push_name ?: $this->numberFromJid($this->confirmingMuteJid)) : null,
            'recentMedia' => $this->showContactPanel ? $this->recentMedia() : [],
        ]);
    }
}
