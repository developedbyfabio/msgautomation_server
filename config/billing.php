<?php

// Fatia 25 — cadastro publico + trial. PONTO UNICO de plano/trial/termos: a
// Fatia 26 (billing/Asaas) le daqui; nada disso e hardcodado em tela.
return [

    // 1 plano no lancamento (decisao do dono). Exibicao pura no /cadastro —
    // NENHUMA cobranca nesta fatia. O preco e PLACEHOLDER ate o Fabio definir
    // (troca por env, sem deploy de codigo).
    'plan' => [
        'name' => env('BILLING_PLAN_NAME', 'Plano Profissional'),
        'price_monthly' => env('BILLING_PLAN_PRICE', '149,90'), // R$/mes — placeholder
        'features' => [
            'WhatsApp conectado ao numero da sua empresa',
            'Respostas automaticas e menus de atendimento',
            'Kanban de conversas, clientes e campanhas',
            'Sugestoes de IA com base de conhecimento propria',
        ],
    ],

    // Trial de 7 dias (decisao do dono). Esta fatia SO grava o marco
    // (subscription_status='trial' + trial_ends_at); o corte e da Fatia 26.
    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 7),

    // Versao dos termos aceitos no cadastro (auditoria LGPD: users.terms_version
    // + terms_accepted_at). Bump manual quando o texto dos termos mudar.
    'terms_version' => '2026-07-06',

];
