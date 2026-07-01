<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Camada 3 (IA) Fatia 1 — toggle e modo de IA POR CONTATO. Aditivo/nao-destrutivo.
 * Defaults preservam o comportamento atual: ai_enabled=false (IA nao age neste contato).
 * ai_mode nasce 'intencao' (quando LIGAR a IA no contato, ela so casa regras existentes),
 * mas so tem efeito com ai_enabled=true. Modos: rules_only|intencao|conhecimento|aprovacao.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->boolean('ai_enabled')->default(false)->after('auto_reply_mode');
            $table->string('ai_mode', 16)->default('intencao')->after('ai_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['ai_enabled', 'ai_mode']);
        });
    }
};
