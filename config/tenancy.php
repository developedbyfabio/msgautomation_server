<?php

/*
|--------------------------------------------------------------------------
| MT-0 — contexto de conta (multi-tenant)
|--------------------------------------------------------------------------
*/

return [
    // FASE 1 (conta unica): sem contexto definido, o AccountContext resolve a conta
    // unica (oldest) — e o UNICO lugar do sistema com esse fallback (era o
    // Account::oldest() espalhado; lacuna L2 do doc 09). Na fase MT-1 (multi-user)
    // este flag vira false: contexto passa a vir SEMPRE do usuario logado/job/comando,
    // e query sem contexto FALHA ALTO (MissingAccountContextException) — nunca
    // vaza/mistura contas em silencio.
    'single_account_fallback' => env('TENANCY_SINGLE_ACCOUNT_FALLBACK', true),
];
