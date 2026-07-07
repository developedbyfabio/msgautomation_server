<?php

namespace App\Servers;

use App\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;

/**
 * Servidores S1 — servidor monitorado (inventario). Feature INTERNA do dono:
 * a area inteira e owner-only (rota + gates), mas o registro participa do
 * escopo por conta como o resto do projeto (BelongsToAccount — decisao da
 * Fase 0: reusar o escopo e mais simples que criar excecao mono-dono).
 *
 * Model em App\Servers (nao App\Models): decisao da S1 — TODO o codigo da
 * feature vive no namespace proprio, isolado do dominio de atendimento.
 *
 * Token do agente: claro SO no Cofre (agent_token_secret_ref = nome do
 * segredo); aqui fica apenas o sha256 (agent_token_hash) pro lookup da
 * ingestao. $hidden evita o hash em payloads serializados (nao e segredo,
 * mas nao ha motivo pra expor).
 */
class Server extends Model
{
    use BelongsToAccount;

    /** SOs suportados na v1 (campo pronto pra 'windows' no futuro). */
    public const OSES = ['linux'];

    protected $fillable = [
        'account_id', 'name', 'host', 'os', 'grupo',
        'agent_token_secret_ref', 'agent_token_hash',
        'enabled', 'last_seen_at', 'last_sample',
    ];

    protected $hidden = [
        'agent_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_seen_at' => 'datetime',
            'last_sample' => 'array',
        ];
    }
}
