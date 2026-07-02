<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Camada 3/5 Fatia 4 — auditoria do loop de aprendizado. Aditivo.
 *
 * Pendencia/decisao PROMOVIDA a regra deterministica ou entrada da base guarda o
 * link (promoted_rule_id / promoted_knowledge_id) — trava nova promocao e mostra
 * o chip "virou regra/entrada" na UI. Sem FK dura (a regra/entrada pode ser
 * excluida sem apagar o historico, como matched_rule_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_approvals', function (Blueprint $table) {
            $table->unsignedBigInteger('promoted_rule_id')->nullable()->after('sent_auto_reply_log_id');
            $table->unsignedBigInteger('promoted_knowledge_id')->nullable()->after('promoted_rule_id');
        });

        Schema::table('ai_decisions', function (Blueprint $table) {
            $table->unsignedBigInteger('promoted_rule_id')->nullable()->after('resposta_resumo');
            $table->unsignedBigInteger('promoted_knowledge_id')->nullable()->after('promoted_rule_id');
        });
    }

    public function down(): void
    {
        Schema::table('pending_approvals', function (Blueprint $table) {
            $table->dropColumn(['promoted_rule_id', 'promoted_knowledge_id']);
        });

        Schema::table('ai_decisions', function (Blueprint $table) {
            $table->dropColumn(['promoted_rule_id', 'promoted_knowledge_id']);
        });
    }
};
