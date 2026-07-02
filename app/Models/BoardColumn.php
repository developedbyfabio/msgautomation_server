<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kanban K-1 — coluna do board. `slug` e a referencia ESTAVEL (regras/automacoes);
 * `name` e o rotulo exibido (editavel na K-2). Escopada via FK do board (padrao
 * das filhas sem account_id).
 */
class BoardColumn extends Model
{
    protected $fillable = ['board_id', 'slug', 'name', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }
}
