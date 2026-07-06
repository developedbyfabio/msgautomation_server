<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fatia 19 (MATCH-2) — forma FONETICA persistida por gatilho (caminho
 * tolerante): evita computar a fonetica do gatilho no hot path (o matcher le a
 * coluna; a da MENSAGEM e 1x por avaliacao). ADITIVA (coluna nullable nas duas
 * tabelas de gatilho). O preenchimento e do comando idempotente
 * `msg:renormalize-triggers` (que tambem re-normaliza o normalized_text com a
 * pipeline nova — squeeze de runs 3+) + do hook saving daqui pra frente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rule_triggers', function (Blueprint $table) {
            $table->string('normalized_phonetic')->nullable()->after('normalized_text');
        });
        Schema::table('flow_triggers', function (Blueprint $table) {
            $table->string('normalized_phonetic')->nullable()->after('normalized_text');
        });
    }

    public function down(): void
    {
        // Remove SO o que esta migration adicionou.
        Schema::table('rule_triggers', function (Blueprint $table) {
            $table->dropColumn('normalized_phonetic');
        });
        Schema::table('flow_triggers', function (Blueprint $table) {
            $table->dropColumn('normalized_phonetic');
        });
    }
};
