<?php

/*
|--------------------------------------------------------------------------
| Proativas P-3 — parametros do disparo
|--------------------------------------------------------------------------
*/

return [
    // Lote MAXIMO de targets enfileirados por rodada do tick (por conta).
    // Pequeno de proposito: o tick roda a cada minuto e nunca raja.
    'tick_batch' => (int) env('PROACTIVE_TICK_BATCH', 5),
];
