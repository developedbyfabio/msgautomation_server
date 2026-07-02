<?php

/*
|--------------------------------------------------------------------------
| MT-0 — contexto de conta (multi-tenant)
|--------------------------------------------------------------------------
*/

return [
    // MT-1: fallback de conta unica DESLIGADO por default — o contexto vem SEMPRE
    // do usuario logado (vinculo account_user), do job (id serializado) ou do
    // comando (--account/iteracao). Query sem contexto FALHA ALTO
    // (MissingAccountContextException) — nunca vaza/mistura contas em silencio.
    // A suite LEGADA (pre-MT-1) roda com o flag ligado via phpunit.xml pra manter
    // a semantica fase-1 dos testes antigos; os testes MT-1 desligam explicito.
    'single_account_fallback' => env('TENANCY_SINGLE_ACCOUNT_FALLBACK', false),
];
