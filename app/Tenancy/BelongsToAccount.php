<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;

/**
 * MT-0 — trait dos models de dominio (tabelas com account_id):
 *  - global scope AccountScope: toda query filtra pela conta do contexto (sem
 *    contexto e sem fallback fase-1 -> excecao; nunca vaza em silencio);
 *  - creating: injeta account_id do contexto quando ausente (creates existentes
 *    que ja passam account_id explicito continuam intocados).
 *
 * Filhas SEM account_id (rule_triggers/responses/ai_examples, flow_nodes/options/
 * triggers, pivos) sao escopadas pela FK do pai — o pai ja filtra.
 *
 * Bypass NOMEADO pra consulta cross-account legitima (ex.: webhook resolvendo a
 * conta pela instancia, manutencao do scheduler): Model::withoutAccountScope().
 * Nunca burlar o scope de outro jeito.
 */
trait BelongsToAccount
{
    protected static function bootBelongsToAccount(): void
    {
        static::addGlobalScope(new AccountScope());

        static::creating(function ($model) {
            if ($model->getAttribute('account_id') === null) {
                $model->setAttribute('account_id', app(AccountContext::class)->id());
            }
        });
    }

    /** Bypass EXPLICITO do escopo por conta (so consultas cross-account nomeadas). */
    public static function withoutAccountScope(): Builder
    {
        return static::query()->withoutGlobalScope(AccountScope::class);
    }
}
