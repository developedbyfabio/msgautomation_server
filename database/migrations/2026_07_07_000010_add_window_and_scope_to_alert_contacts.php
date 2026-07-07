<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Servidores — janela de horario + escopo de servidores POR DESTINATARIO. ADITIVA.
 *
 * Feature 1 (janela): quando o contato recebe.
 *  - window_mode: '24h' (padrao, recebe sempre) | 'custom' (janela inicio-fim).
 *  - window_start/window_end: 'HH:MM' (fuso America/Sao_Paulo na avaliacao).
 *  - weekends: recebe sabado/domingo? (independente da janela de horario).
 * Fora da janela o WhatsApp DAQUELE contato e suprimido (descarte por-contato,
 * sem acumulo); o incidente + a conversa "Alertas de Infraestrutura" seguem
 * registrados pelo AlertNotifier (fato preservado).
 *
 * Feature 2 (escopo): quais servidores o contato recebe.
 *  - server_ids: JSON com a lista de servidores escolhidos; NULL/vazio = todos.
 *    Tem precedencia sobre o alvo legado (server_id/grupo), que segue valendo
 *    para linhas antigas nao editadas.
 *
 * Defaults preservam o comportamento atual: 24h + fim de semana + todos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_alert_contacts', function (Blueprint $table) {
            $table->string('window_mode', 10)->default('24h')->after('min_level'); // 24h|custom
            $table->string('window_start', 5)->nullable()->after('window_mode');    // HH:MM
            $table->string('window_end', 5)->nullable()->after('window_start');      // HH:MM
            $table->boolean('weekends')->default(true)->after('window_end');
            $table->json('server_ids')->nullable()->after('grupo'); // Feature 2: selecao
        });
    }

    public function down(): void
    {
        Schema::table('server_alert_contacts', function (Blueprint $table) {
            $table->dropColumn(['window_mode', 'window_start', 'window_end', 'weekends', 'server_ids']);
        });
    }
};
