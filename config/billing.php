<?php

// Fatia 25 — cadastro publico + trial. PONTO UNICO de plano/trial/termos: a
// Fatia 26 (billing/Asaas) le daqui; nada disso e hardcodado em tela.
return [

    // 1 plano no lancamento (decisao do dono). Exibicao pura no /cadastro —
    // NENHUMA cobranca nesta fatia. O preco e PLACEHOLDER ate o Fabio definir
    // (troca por env, sem deploy de codigo).
    'plan' => [
        'name' => env('BILLING_PLAN_NAME', 'Plano Profissional'),
        // Fatia 26: BILLING_PLAN_VALUE (numerico) e A fonte do preco — vira o
        // 'value' da assinatura no Asaas E o texto exibido (derivado abaixo).
        // Placeholder ate o Fabio definir. (BILLING_PLAN_PRICE da fatia 25 foi
        // absorvido: exibicao e derivada, um env so.)
        'price' => (float) env('BILLING_PLAN_VALUE', 149.90),
        'price_monthly' => number_format((float) env('BILLING_PLAN_VALUE', 149.90), 2, ',', '.'),
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

    // Fatia 26 — politica do corte: trial vencido sem pagamento -> 'overdue';
    // apos N dias em overdue -> 'suspended' (owner so acessa a billing; bot
    // para; NADA e apagado — reversivel por pagamento confirmado no webhook).
    'overdue_grace_days' => (int) env('BILLING_OVERDUE_GRACE_DAYS', 5),

    // Fatia 26 — Asaas (sandbox-first). Segredos SO no .env, nunca no codigo.
    'asaas' => [
        'api_key' => env('ASAAS_API_KEY'),
        'base_url' => env('ASAAS_BASE_URL', 'https://api-sandbox.asaas.com'),
        'webhook_token' => env('ASAAS_WEBHOOK_TOKEN'),
    ],

];
