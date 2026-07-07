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

    /*
     * A3 — cadencia ESPERADA do agente (segundos). A histerese ("for duration")
     * e medida em SEGUNDOS de condicao continua; a cadencia so serve para
     * derivar o gap maximo tolerado entre amostras (uma amostra perdida nao
     * quebra a janela; duas seguidas, sim — sem prova de continuidade). Mudar a
     * cadencia do agente NAO muda a latencia do alerta (que segue o for_s).
     */
    'cadence_s' => (int) env('SERVERS_CADENCE_S', 30),

    /*
     * A3/A6 — teto do for_duration (subida e descida) por regra, em segundos.
     * Amarra a UI e garante que o buffer recente (MetricsBuffer) SEMPRE cobre a
     * maior janela em uso: com cadencia >= 15s e 60 amostras, a cobertura e
     * >= 900s > este teto. Sem isto, uma regra com for_s alto nunca dispararia
     * (o buffer nao guarda janela suficiente).
     */
    'max_for_duration_s' => (int) env('SERVERS_MAX_FOR_DURATION_S', 600),

];
