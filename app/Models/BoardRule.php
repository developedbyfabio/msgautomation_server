<?php

namespace App\Models;

use App\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kanban K-1 — regra de movimento: "evento X (condicoes minimas) -> coluna Y".
 * Avaliadas em ordem (position); a PRIMEIRA que casa move (first-match). Condicoes
 * suportadas (JSON): {"card":"absent|present"}, {"card_in_column":"slug"},
 * {"not_in_column":"slug"}. Editaveis na UI da K-2.
 */
class BoardRule extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'account_id',
        'board_id',
        'event_type',   // mensagem_recebida | resposta_enviada | envio_manual | fluxo_no | ia_decisao
        'conditions',
        'to_column_id',
        'active',
        'is_default', // regra do provisioner (editar/desativar pede confirmacao na UI)
        'position',
    ];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'active' => 'boolean',
            'is_default' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function toColumn(): BelongsTo
    {
        return $this->belongsTo(BoardColumn::class, 'to_column_id');
    }
}
