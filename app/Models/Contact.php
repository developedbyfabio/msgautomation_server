<?php

namespace App\Models;

use App\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Contact extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'account_id',
        'remote_jid',
        'push_name',
        'auto_reply_opt_out', // DEPRECIADO: usar auto_reply_mode
        'auto_reply_mode',    // default | on | off
        'notes',
        'saved',              // true = nomeado/adicionado pelo usuario (S4)
        'ai_enabled',         // IA por contato (Camada 3). Default false.
        'ai_mode',            // rules_only | intencao | conhecimento | aprovacao
        'proactive_opt_in',   // P-1: opt-in EXPLICITO pra receber proativas (default false)
        'is_system',          // contato de SISTEMA (Alertas de Infra): so exibicao, fora do pipeline
    ];

    protected function casts(): array
    {
        return [
            'auto_reply_opt_out' => 'boolean',
            'saved' => 'boolean',
            'ai_enabled' => 'boolean',
            'proactive_opt_in' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** Tags T-1 — segmentacao (pivo com origem: manual | board_rule | ai_intent). */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'contact_tag')
            ->withPivot(['origin', 'origin_ref'])
            ->withTimestamps();
    }

    /**
     * Fatia 23 — exibicao AMIGAVEL do identificador (SO view: o remote_jid
     * armazenado e o matching ficam intactos). Numero BR vira telefone
     * formatado; fora do padrao, cai no numero cru sem o sufixo tecnico.
     */
    public function displayPhone(): string
    {
        $num = Str::before((string) $this->remote_jid, '@');
        if (preg_match('/^55(\d{2})(\d{4,5})(\d{4})$/', $num, $m)) {
            return "+55 ({$m[1]}) {$m[2]}-{$m[3]}";
        }

        return $num !== '' ? $num : (string) $this->remote_jid;
    }
}
