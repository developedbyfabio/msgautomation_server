<?php

namespace App\Models;

use App\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutoReplyLog extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'account_id',
        'channel_id',
        'incoming_message_id',
        'rule_id',
        'campaign_id', // P-3: envio proativo rastreado (mode='proactive')
        'remote_jid',
        'mode',
        'response_text',
        'media_path',  // Prompt 04: anexo enviado (disco 'local', media/{conta}/...)
        'media_mime',
        'status',
        'motivo',
        'provider_message_id',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
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

    public function incomingMessage(): BelongsTo
    {
        return $this->belongsTo(IncomingMessage::class);
    }
}
