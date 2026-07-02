<?php

namespace App\Models;

use App\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Tags T-1 — segmentacao por contato (nome unico POR CONTA, cor da paleta de
 * badges). Aplicada na mao (painel do contato) ou por board_rule (acao add_tag/
 * remove_tag) com origem rastreada no pivo. Tags NUNCA enviam nada — segmentam:
 * escopo de regras/fluxos e, nas P-fatias, das campanhas proativas.
 *
 * GUARDA S5: regra que devolve {senha:} NAO pode ter escopo por tag — tag e
 * dinamica (um evento pode aplica-la); segredo exige lista explicita de contatos.
 */
class Tag extends Model
{
    use BelongsToAccount;

    /** Paleta pequena (classes de badge existentes no app). */
    public const COLORS = ['zinc', 'red', 'amber', 'emerald', 'sky', 'indigo', 'purple', 'pink'];

    protected $fillable = ['account_id', 'name', 'color'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'contact_tag')
            ->withPivot(['origin', 'origin_ref'])
            ->withTimestamps();
    }
}
