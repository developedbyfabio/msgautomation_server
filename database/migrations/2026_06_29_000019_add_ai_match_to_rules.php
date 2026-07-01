<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Camada 3 (IA) Fatia 1 — "deixe a IA casar mensagens parecidas com esta regra".
 * Aditivo: ai_match_enabled default false (nenhuma regra participa da IA ate o Fabio
 * ligar). Tabela filha rule_ai_examples guarda frases-exemplo OPCIONAIS da intencao
 * (ex.: "me fala a hora ai" pra uma regra "que horas sao?"). A resposta continua vindo
 * do RuleResponder — a IA so ajuda a identificar a intencao.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_reply_rules', function (Blueprint $table) {
            $table->boolean('ai_match_enabled')->default(false)->after('scope');
        });

        Schema::create('rule_ai_examples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auto_reply_rule_id')->constrained()->cascadeOnDelete();
            $table->string('phrase');
            $table->timestamps();
            $table->index('auto_reply_rule_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_ai_examples');

        Schema::table('auto_reply_rules', function (Blueprint $table) {
            $table->dropColumn('ai_match_enabled');
        });
    }
};
