<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Decisao da IA (Camada 3): o que a IA classificou e o que fez com a mensagem.
 * acao: respondeu | escalou | silenciou. Alimenta a revisao/loop da Camada 5.
 * NUNCA guarda valor de segredo nem a mensagem crua.
 */
class AiDecision extends Model
{
    protected $fillable = [
        'account_id',
        'contact_id',
        'incoming_message_id',
        'matched_rule_id',
        'remote_jid',
        'intent',
        'confidence',
        'acao',
        'motivo',
        'model',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function incomingMessage(): BelongsTo
    {
        return $this->belongsTo(IncomingMessage::class);
    }
}
