<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fatia 1 — Modo de Operacao (Pessoal vs Automatico). ADITIVA e INERTE: ninguem
 * le estas colunas ainda (o pipeline nao muda). Default 'personal' = comportamento
 * atual. `default_flow_id` (FK nullable -> flows, nullOnDelete) aponta o fluxo
 * catch-all do modo automatico (usado na fatia 4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_reply_settings', function (Blueprint $table) {
            $table->string('operation_mode', 16)->default('personal')->after('reply_policy');
            $table->foreignId('default_flow_id')->nullable()->after('operation_mode')
                ->constrained('flows')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('auto_reply_settings', function (Blueprint $table) {
            $table->dropForeign(['default_flow_id']);
            $table->dropColumn(['operation_mode', 'default_flow_id']);
        });
    }
};
