<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proativas P-3 — disparo real (TUDO OFF ate o gate do Fabio). Aditivo.
 *
 * - campaign_targets.sent_auto_reply_log_id: liga o envio ao log (auditoria).
 *   status ganha processing|sent|failed (colunas string ja comportam);
 *   skip_reason vale tambem como motivo de failed.
 * - auto_reply_logs.campaign_id: origem proactive rastreada no log de envio
 *   (mode='proactive'); sem FK dura pra campanha nao prender o historico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_targets', function (Blueprint $table) {
            $table->foreignId('sent_auto_reply_log_id')->nullable()->after('sent_at')
                ->constrained('auto_reply_logs')->nullOnDelete();
        });

        Schema::table('auto_reply_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('campaign_id')->nullable()->after('rule_id');
            $table->index('campaign_id');
        });
    }

    public function down(): void
    {
        Schema::table('auto_reply_logs', function (Blueprint $table) {
            $table->dropIndex(['campaign_id']);
            $table->dropColumn('campaign_id');
        });

        Schema::table('campaign_targets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sent_auto_reply_log_id');
        });
    }
};
