<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kanban K-1 — historico de movimento do card. from/to sem FK dura (colunas podem
 * ser remodeladas sem apagar historico). cause: regra (board_rule_id + evento) |
 * manual (K-2) | tempo (P-fatias). Unique (card, event_type, event_ref) garante
 * idempotencia de re-entrega. Escopada via FK do card.
 */
class CardTransition extends Model
{
    protected $fillable = [
        'card_id',
        'from_column_id', // null = card criado
        'to_column_id',
        'cause',          // regra | manual | tempo
        'board_rule_id',
        'event_type',
        'event_ref',
    ];

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }
}
