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
        'slug',        // Fatia 15: identidade ESTAVEL do token {kb:slug}
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

    protected static function booted(): void
    {
        // Fatia 15 — slug gerado na CRIACAO (choke point unico: cobre writer,
        // seed e qualquer caminho de criacao) e IMUTAVEL depois: renomear o
        // titulo NUNCA quebra um {kb:slug} ja usado em mensagem. Unico por conta.
        static::creating(function (self $k) {
            if (trim((string) $k->slug) === '') {
                $k->slug = self::uniqueSlugFor((int) $k->account_id, (string) $k->title);
            }
        });
    }

    /** Slug unico POR CONTA a partir do titulo (colisao sufixa -2, -3...). */
    public static function uniqueSlugFor(int $accountId, string $title): string
    {
        $base = \Illuminate\Support\Str::slug(\Illuminate\Support\Str::limit($title, 70, ''), '-') ?: 'conhecimento';
        $slug = $base;
        $n = 2;
        while (self::withoutAccountScope()->where('account_id', $accountId)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$n}";
            $n++;
        }

        return $slug;
    }

    /**
     * Fatia 15 — entradas REFERENCIAVEIS por {kb:slug} em mensagem automatica:
     * ativas, sensitivity 'low' (medium/high vao no maximo ao MODELO da IA,
     * nunca direto pro contato) e SEM restricao de contatos (mensagem de fluxo/
     * regra vai pra qualquer contato — entrada restrita nao pode vazar). A
     * guarda extra de {senha:} no conteudo fica nos consumidores (via hasRef).
     */
    public function scopeReferenciavel(Builder $query, int $accountId): Builder
    {
        return $query
            ->withoutGlobalScopes()
            ->where('account_id', $accountId)
            ->where('active', true)
            ->where('sensitivity', 'low')
            ->whereDoesntHave('contacts');
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
