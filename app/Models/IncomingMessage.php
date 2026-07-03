<?php

namespace App\Models;

use App\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncomingMessage extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'account_id',
        'channel_id',
        'instance',
        'evolution_message_id',
        'remote_jid',
        'from_me',
        'push_name',
        'type',
        'text',
        'media_path',
        'media_mime',
        'media_name',
        'media_status',
        'raw_payload',
        'received_at',
    ];

    /**
     * Prompt 13 — categoria de midia baixavel: 'image' | 'audio' | null.
     * Cobre os nomes dos dois provedores (Evolution *Message / Cloud simples).
     * Escopo desta fatia: imagem e audio (video/documento ficam pra depois).
     */
    public function mediaCategory(): ?string
    {
        return match ($this->type) {
            'imageMessage', 'image' => 'image',
            'audioMessage', 'pttMessage', 'audio' => 'audio',
            default => null,
        };
    }

    protected function casts(): array
    {
        return [
            'from_me' => 'boolean',
            'raw_payload' => 'array',
            'received_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
