<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * MT-0 — global scope: TODA query de model de dominio filtra pela conta do
 * contexto. Sem contexto (e sem fallback fase-1), o AccountContext::id() lanca
 * MissingAccountContextException — a query FALHA ALTO em vez de retornar dados
 * de outra conta. Bypass legitimo (cross-account): Model::withoutAccountScope().
 */
class AccountScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('account_id'), app(AccountContext::class)->id());
    }
}
