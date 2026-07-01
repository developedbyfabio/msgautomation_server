<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Camada 3 (IA) Fatia 1 — configuracao global da IA (1 linha/account). Aditivo.
 *  - ai_enabled: KILL SWITCH PROPRIO da IA (separado do robo `enabled`). Default false.
 *  - ai_confidence_threshold: limiar de confianca (abaixo -> escala). Default 0.75.
 *  - ai_approval_topics: temas que SEMPRE exigem aprovacao (JSON). NULL = todos ligados
 *    (o model resolve o default; JSON nao aceita default no MySQL).
 *
 * Tudo OFF/conservador por padrao: com esta fatia commitada e nada ligado, o robo se
 * comporta EXATAMENTE como hoje.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_reply_settings', function (Blueprint $table) {
            $table->boolean('ai_enabled')->default(false)->after('contact_rate_enabled');
            $table->decimal('ai_confidence_threshold', 3, 2)->default(0.75)->after('ai_enabled');
            $table->json('ai_approval_topics')->nullable()->after('ai_confidence_threshold');
        });
    }

    public function down(): void
    {
        Schema::table('auto_reply_settings', function (Blueprint $table) {
            $table->dropColumn(['ai_enabled', 'ai_confidence_threshold', 'ai_approval_topics']);
        });
    }
};
