<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Servidores S3 / auditoria A4 — debounce SIMETRICO de resolucao. ADITIVA.
 *
 * resolve_for_s: por quanto tempo (s) a metrica precisa ficar ABAIXO do limiar
 * antes de o incidente resolver (anti-flapping). NULL = usa warning_for_s (a
 * janela de subida) — resolver exige a mesma persistencia que abrir. Coluna
 * nova nullable; regras existentes ficam NULL (comportamento identico ao
 * anterior, que ja usava max(warning_for_s, 60)).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_alert_rules', function (Blueprint $table) {
            $table->unsignedInteger('resolve_for_s')->nullable()->after('critical_for_s');
        });
    }

    public function down(): void
    {
        Schema::table('server_alert_rules', function (Blueprint $table) {
            $table->dropColumn('resolve_for_s');
        });
    }
};
