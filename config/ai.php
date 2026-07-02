<?php

/*
|--------------------------------------------------------------------------
| Camada 3 (IA) — parametros do app (o driver Gemini vive em services.php)
|--------------------------------------------------------------------------
*/

return [
    // Fatia 3: pendencia de aprovacao com mais de N dias vira 'expired'
    // automaticamente (nada e enviado). 0/negativo = nunca expira.
    'approval_expire_days' => (int) env('AI_APPROVAL_EXPIRE_DAYS', 7),
];
