<?php

namespace App\Tenancy;

use App\Models\Account;

/**
 * MT-0 — contexto da CONTA ATUAL (singleton por request/processo). Fonte unica de
 * verdade de "qual conta estamos operando":
 *
 *  - WEB: middleware SetAccountContext define no inicio do request (fase 1: conta
 *    unica; MT-1: a conta do usuario logado — e o unico ponto a trocar).
 *  - JOBS: cada job define explicitamente no handle (account_id serializado ou
 *    resolvido do proprio registro via bypass). Queue::before limpa entre jobs
 *    (nenhum job herda contexto do anterior).
 *  - COMANDOS/SCHEDULER: --account= ou iteracao explicita por conta.
 *
 * FASE 1 (config tenancy.single_account_fallback=true): sem contexto definido,
 * id() resolve a conta unica (oldest) — fallback EXPLICITO e CENTRALIZADO, o
 * substituto do Account::oldest() que vivia espalhado (L2). Com o flag desligado
 * (MT-1+), id() sem contexto FALHA ALTO — vazamento silencioso e impossivel.
 */
class AccountContext
{
    private ?int $accountId = null;

    /** @var array<int,?int> pilha pra jobs ANINHADOS (fila sync) — ver push/pop */
    private array $stack = [];

    public function set(int $accountId): void
    {
        $this->accountId = $accountId;
    }

    public function has(): bool
    {
        return $this->accountId !== null;
    }

    public function clear(): void
    {
        $this->accountId = null;
    }

    /**
     * Higiene da FILA (Queue::before): guarda o contexto atual e comeca limpo —
     * cada job define o proprio contexto. Com fila SYNC (testes/dispatchSync), um
     * listener/job aninhado NAO pode apagar o contexto do job PAI: o pop() (
     * Queue::after / exceptionOccurred) restaura exatamente o que havia antes.
     * No worker longevo a pilha e rasa (1 job por vez) e o pop devolve o vazio.
     */
    public function push(): void
    {
        $this->stack[] = $this->accountId;
        $this->accountId = null;
    }

    public function pop(): void
    {
        $this->accountId = $this->stack === [] ? null : array_pop($this->stack);
    }

    /** A conta atual. Sem contexto: fallback fase-1 (conta unica) ou EXCECAO. */
    public function id(): int
    {
        if ($this->accountId !== null) {
            return $this->accountId;
        }

        if (config('tenancy.single_account_fallback', true)) {
            $id = Account::query()->oldest('id')->value('id');
            if ($id !== null) {
                // Cacheia no request/processo (jobs limpam via Queue::before).
                $this->accountId = (int) $id;

                return $this->accountId;
            }
        }

        throw new MissingAccountContextException();
    }

    /** Roda um trecho com OUTRA conta e restaura o contexto anterior (comandos/scheduler). */
    public function runAs(int $accountId, callable $fn): mixed
    {
        $anterior = $this->accountId;
        $this->accountId = $accountId;

        try {
            return $fn();
        } finally {
            $this->accountId = $anterior;
        }
    }
}
