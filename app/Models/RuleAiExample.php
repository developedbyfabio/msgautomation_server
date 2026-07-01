<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Frase-exemplo de uma regra (Camada 3): "me fala a hora ai" pra uma regra
 * "que horas sao?". Exemplo de MENSAGEM do contato — nunca resposta/segredo.
 */
class RuleAiExample extends Model
{
    protected $fillable = [
        'auto_reply_rule_id',
        'phrase',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutoReplyRule::class, 'auto_reply_rule_id');
    }
}
