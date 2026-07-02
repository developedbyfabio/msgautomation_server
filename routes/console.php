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
