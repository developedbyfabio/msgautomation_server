<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Fatia 3 — expira pendencias de aprovacao velhas (nada e enviado). O /revisao
// tambem expira lazy no mount; este agendamento cobre quando o scheduler rodar.
Illuminate\Support\Facades\Schedule::command('ai:expire-approvals')->dailyAt('03:00');

// Proativas P-3 — tick por minuto: so ENFILEIRA envios vencidos de contas com o
// interruptor proativo ligado (hoje nenhuma; roda barato e nao faz nada).
Illuminate\Support\Facades\Schedule::command('proactive:tick')->everyMinute();

// MATCH-1 — retencao do log de sem-match (30 dias; leve, roda de madrugada).
Illuminate\Support\Facades\Schedule::command('unmatched:prune')->dailyAt('03:10');

// Fatia 26 — corte de trial/inadimplencia (diario): trial vencido -> overdue;
// overdue alem da carencia -> suspended. Reversivel (webhook de pagamento
// reativa); contas legacy sao imunes por construcao. NADA e apagado.
Illuminate\Support\Facades\Schedule::command('billing:sweep')->dailyAt('03:20');
