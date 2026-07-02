<?php

namespace App\Models;

use App\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * MATCH-1 — log de SEM-MATCH: mensagem que terminou em silencio ELEGIVEL
 * ("nenhuma regra/fluxo casou e a IA nao respondeu", contato aprovado; grupos
 * e opt-out NAO entram). A licao do "Que horas são?": silencio sem registro e
 * oportunidade perdida — o painel mostra e o "virar regra" resolve.
 * Retencao: 30 dias (unmatched:prune agendado).
 */
class UnmatchedMessage extends Model
{
    use BelongsToAccount;

    protected $fillable = ['account_id', 'contact_id', 'text'];

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    /** Registro central (unico ponto de escrita). Texto truncado em 200. */
    public static function record(int $accountId, ?string $remoteJid, ?string $texto): void
    {
        $texto = trim((string) $texto);
        if ($texto === '') {
            return;
        }

        $contactId = $remoteJid !== null
            ? Contact::withoutAccountScope()->where('account_id', $accountId)
                ->where('remote_jid', $remoteJid)->value('id')
            : null;

        static::withoutAccountScope()->create([
            'account_id' => $accountId,
            'contact_id' => $contactId,
            'text' => Str::limit($texto, 200, ''),
        ]);
    }
}
