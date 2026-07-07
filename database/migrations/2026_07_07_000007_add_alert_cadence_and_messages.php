<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Servidores — mensagens configuraveis + cadencia de re-aviso por regra/nivel.
 * ADITIVA.
 *
 *  server_alert_rules: warning_repeat_s / critical_repeat_s — cadencia de
 *  RE-AVISO por nivel. NULL = "avisar 1 vez" (nao re-avisa); >0 = re-avisar a
 *  cada N segundos enquanto o incidente segue aberto (nao-reconhecido).
 *  Sobrescreve o default antigo (so critical re-notificava a cada cooldown_s):
 *  seed dos existentes preserva o comportamento (critical <- cooldown_s;
 *  warning fica NULL = 1 vez).
 *
 *  server_incidents.notify_count: quantas notificacoes de ABERTURA/RE-AVISO ja
 *  sairam para este incidente — indice de ROTACAO da lista de mensagens
 *  (0 = 1a mensagem; avanca a cada re-aviso; repete a ultima ao acabar a lista).
 *
 *  server_alert_messages: lista de mensagens por (regra, nivel) para rotacao +
 *  o texto de resolucao. level = warning|critical|resolved. Vazio = texto
 *  padrao sensato (AlertMessageResolver). Variaveis no texto: {servidor}
 *  {metrica} {valor} {nivel} {particao} — substituidas no envio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_alert_rules', function (Blueprint $table) {
            $table->unsignedInteger('warning_repeat_s')->nullable()->after('resolve_for_s');
            $table->unsignedInteger('critical_repeat_s')->nullable()->after('warning_repeat_s');
        });

        // Preserva o comportamento S3: critical re-notificava a cada cooldown_s.
        DB::table('server_alert_rules')->update(['critical_repeat_s' => DB::raw('cooldown_s')]);
        // watchdog e outras: mantem critical <- cooldown_s tambem (ajustavel na UI).

        Schema::table('server_incidents', function (Blueprint $table) {
            $table->unsignedInteger('notify_count')->default(0)->after('last_notified_at');
        });

        Schema::create('server_alert_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rule_id')->constrained('server_alert_rules')->cascadeOnDelete();
            $table->string('level', 10); // warning | critical | resolved
            $table->unsignedInteger('position')->default(0);
            $table->text('text');
            $table->timestamps();

            $table->index(['rule_id', 'level', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_alert_messages');
        Schema::table('server_incidents', function (Blueprint $table) {
            $table->dropColumn('notify_count');
        });
        Schema::table('server_alert_rules', function (Blueprint $table) {
            $table->dropColumn(['warning_repeat_s', 'critical_repeat_s']);
        });
    }
};
