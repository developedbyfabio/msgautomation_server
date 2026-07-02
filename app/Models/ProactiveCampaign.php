<?php

namespace App\Models;

use App\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Proativas P-2 — campanha com gate humano estrutural. Ciclo: draft -> previewed
 * -> approved (SNAPSHOT: targets + agenda congelados; mensagem/publico TRAVADOS)
 * -> cancelled. running/done/paused chegam na P-3. Publico SO entre opt-ins
 * (filtro estrutural no AudienceResolver). {senha:} proibido na mensagem.
 */
class ProactiveCampaign extends Model
{
    use BelongsToAccount;

    public const AUDIENCE_TYPES = ['tags', 'coluna_kanban', 'contatos'];

    protected $fillable = [
        'account_id',
        'name',
        'message',
        'optout_footer',   // P-4: rodape de saida (template; {palavra_sair} resolve no envio)
        'audience_type',   // tags | coluna_kanban | contatos
        'audience_config', // JSON por tipo
        'status',          // draft | previewed | approved | cancelled (P-3: running|done|paused)
        'start_at',
        'approved_at',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'audience_config' => 'array',
            'start_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(CampaignTarget::class, 'campaign_id');
    }

    /** Editavel? So antes de aprovar (aprovado = snapshot TRAVADO). */
    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'previewed'], true);
    }
}
