<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Servidores S4 — seleção de PARTIÇÃO por servidor. ADITIVA.
 *
 * Ate a S2/S3 a regra de disco era UMA por servidor/global, aplicada a TODAS as
 * particoes com o mesmo limiar. Para o dono escolher QUAIS particoes alertar e
 * o limiar de cada uma (no painel), a regra de disco ganha alvo por MOUNT.
 *
 * mount NULL = regra de disco "para todas as particoes" (comportamento atual,
 * intacto); mount = '/boot' etc. = sobrescrita daquela particao. Precedencia na
 * avaliacao (mais especifica primeiro): (servidor, mount) > (servidor, NULL) >
 * (global, NULL). Uma regra de particao com enabled=false SILENCIA so aquela
 * particao naquele servidor (ex.: parar de vigiar /srv). Regras existentes
 * ficam mount=NULL -> nada muda ate o dono criar uma sobrescrita por particao.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_alert_rules', function (Blueprint $table) {
            $table->string('mount', 120)->nullable()->after('metric');
            $table->index(['account_id', 'server_id', 'metric', 'mount']);
        });
    }

    public function down(): void
    {
        Schema::table('server_alert_rules', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'server_id', 'metric', 'mount']);
            $table->dropColumn('mount');
        });
    }
};
