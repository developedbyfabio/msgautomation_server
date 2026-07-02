<?php

namespace App\Models;

use App\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kanban K-1 — board de conversas (D4: 1 board default por conta; schema ja
 * permite N pra fase futura). O Kanban e OBSERVADOR PURO do pipeline.
 */
class Board extends Model
{
    use BelongsToAccount;

    protected $fillable = ['account_id', 'name', 'is_default'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function columns(): HasMany
    {
        return $this->hasMany(BoardColumn::class)->orderBy('position');
    }

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(BoardRule::class)->orderBy('position');
    }

    /** Coluna pelo slug estavel (novo, em_atendimento, ...). */
    public function column(string $slug): ?BoardColumn
    {
        return $this->columns->firstWhere('slug', $slug);
    }
}
