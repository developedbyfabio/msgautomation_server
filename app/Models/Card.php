<?php

namespace App\Models;

use App\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kanban K-1 — card de conversa: UM por contato por board (unique). Movido por
 * eventos do pipeline via BoardEngine (observador puro; nunca envia). Grupos
 * ficam fora do Kanban (coerente com o robo, que pula grupos).
 */
class Card extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'account_id',
        'board_id',
        'contact_id',
        'column_id',
        'last_interaction_at',
        'last_direction',      // in | out
        'pinned_until_reply',  // Fatia 20: movido por humano — automatico nao mexe ate o contato responder
        'archived_at',         // Fatia 20: arquivado (reversivel; nunca delete fisico)
    ];

    protected function casts(): array
    {
        return [
            'last_interaction_at' => 'datetime',
            'pinned_until_reply' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function column(): BelongsTo
    {
        return $this->belongsTo(BoardColumn::class, 'column_id');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(CardTransition::class)->latest('id');
    }
}
