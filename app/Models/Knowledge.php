<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use App\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Entrada da base de conhecimento (Camada 3, modo `conhecimento`).
 *
 * sensitivity: low | medium | high. REGRA DURA: `high` NUNCA vai pro modelo e NUNCA
 * e respondido direto — a decisao escala pra revisao humana (fila na Fatia 3).
 * content pode conter placeholders ({senha:nome}, {nome}, ...) — o texto vai ao
 * modelo COM o placeholder intacto; o valor real so e resolvido no envio (Sender).
 */
class Knowledge extends Model
{
    use BelongsToAccount;

    /** Nome singular da tabela (nao ha plural de "knowledge"). */
    protected $table = 'knowledge';

    public const SENSITIVITIES = ['low', 'medium', 'high'];

    protected $fillable = [
        'account_id',
        'title',
        'content',
        'sensitivity', // low | medium | high
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** Contatos com permissao. SEM linhas = disponivel pra qualquer contato com IA. */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'knowledge_contacts');
    }

    /**
     * Entradas candidatas pra um contato: ativas e permitidas (pivo vazio = todas;
     * pivo preenchido = so se o contato esta na lista). NAO filtra sensibilidade —
     * quem consome separa low/medium (vao ao modelo) de high (nunca vai; escala).
     */
    public function scopeCandidatesFor(Builder $query, int $accountId, int $contactId): Builder
    {
        return $query
            ->where('account_id', $accountId)
            ->where('active', true)
            ->where(function ($q) use ($contactId) {
                $q->whereDoesntHave('contacts')
                    ->orWhereHas('contacts', fn ($c) => $c->where('contacts.id', $contactId));
            })
            ->orderBy('id');
    }
}
