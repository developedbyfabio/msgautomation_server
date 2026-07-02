<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proativas P-1 (N8 do doc 09) — A JAULA ANTES DO ANIMAL. Aditivo.
 *
 * Mensagem proativa (o sistema inicia) e o MAIOR risco de ban do roadmap. P-1 so
 * constroi os freios: settings proprias (kill switch INDEPENDENTE nascendo OFF +
 * defaults conservadores da D5), opt-in explicito por contato e a trilha de
 * consentimento AUDITAVEL (grant/revoke com origem; nunca apagada — LGPD: prova
 * de consentimento e de revogacao). NENHUM caminho de envio proativo existe.
 *
 * Defaults D5 aprovados: 20/dia por conta, 1/contato/semana, janela 09-18h (SP),
 * jitter 3-15min (scheduler da P-2), palavra de opt-out "PARAR".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_reply_settings', function (Blueprint $table) {
            $table->boolean('proactive_enabled')->default(false)->after('ai_approval_topics');
            $table->unsignedInteger('proactive_daily_cap')->default(20)->after('proactive_enabled');
            $table->unsignedInteger('proactive_per_contact_weekly_cap')->default(1)->after('proactive_daily_cap');
            $table->time('proactive_window_start')->default('09:00:00')->after('proactive_per_contact_weekly_cap');
            $table->time('proactive_window_end')->default('18:00:00')->after('proactive_window_start');
            $table->unsignedInteger('proactive_jitter_min')->default(3)->after('proactive_window_end');
            $table->unsignedInteger('proactive_jitter_max')->default(15)->after('proactive_jitter_min');
            $table->string('proactive_optout_word', 40)->default('PARAR')->after('proactive_jitter_max');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->boolean('proactive_opt_in')->default(false)->after('ai_mode');
        });

        Schema::create('proactive_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('action', 8);   // grant | revoke
            $table->string('origin', 16);  // manual | palavra
            $table->timestamps();

            $table->index(['account_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proactive_consents');

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('proactive_opt_in');
        });

        Schema::table('auto_reply_settings', function (Blueprint $table) {
            $table->dropColumn([
                'proactive_enabled', 'proactive_daily_cap', 'proactive_per_contact_weekly_cap',
                'proactive_window_start', 'proactive_window_end',
                'proactive_jitter_min', 'proactive_jitter_max', 'proactive_optout_word',
            ]);
        });
    }
};
