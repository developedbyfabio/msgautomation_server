<?php

/*
 * Servidores — monitoramento de infra (ferramenta interna do dono).
 */
return [

    /*
     * S2 — MODO SILENCIOSO. false = as transicoes de incidente (firing/
     * escalated/resolved) apenas registram SystemEvent ("teria notificado...")
     * para calibracao dos limiares SEM nenhum envio de WhatsApp. A S3 liga o
     * canal real colocando o envio atras deste flag (e so entao ele deve ser
     * mudado para true via .env).
     */
    'notifications_enabled' => env('SERVERS_NOTIFICATIONS_ENABLED', false),

];
