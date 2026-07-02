<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Camada 3 (IA) Fatia 2 — rastreio das decisoes do modo `conhecimento`. Aditivo.
 *  - origem: 'regra' (Fatia 1, casou regra por IA) | 'base' (respondeu/decidiu pela
 *    base de conhecimento). Default 'regra' preserva as linhas existentes.
 *  - knowledge_ids: ids das entradas da base usadas na resposta (JSON, sem FK dura —
 *    a entrada pode ser excluida sem apagar o historico).
 *  - resposta_resumo: resumo REDIGIDO da resposta enviada/sugerida ({senha:nome} vira
 *    [senha: nome]; valor de segredo NUNCA e gravado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_decisions', function (Blueprint $table) {
            $table->string('origem', 8)->default('regra')->after('acao'); // regra | base
            $table->json('knowledge_ids')->nullable()->after('origem');
            $table->string('resposta_resumo', 200)->nullable()->after('knowledge_ids');
        });
    }

    public function down(): void
    {
        Schema::table('ai_decisions', function (Blueprint $table) {
            $table->dropColumn(['origem', 'knowledge_ids', 'resposta_resumo']);
        });
    }
};
