<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Regras v2 — aditivo/nao-destrutivo. Os defaults preservam o comportamento atual
 * (cooldown = rate-por-contato global; escopo = global; precisao = exato), entao
 * NAO precisa backfill: as colunas novas ja entram com o valor certo nas linhas
 * existentes.
 *
 * - auto_reply_rules.cooldown_mode: 'global' (default, = rate-por-contato global) |
 *   'sempre' | '1x_dia' | 'cada_n'. cooldown_minutes: usado por 'cada_n'.
 * - auto_reply_rules.scope: 'global' (todos aprovados) | 'contatos' (lista em rule_contacts).
 * - rule_triggers.precision: 'exato' (default, comportamento atual) | 'tolerante'.
 *   fuzzy_level: 'baixa' | 'media' | 'alta' (so quando tolerante).
 * - rule_contacts: ponte regra <-> contato (escopo 'contatos').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_reply_rules', function (Blueprint $table) {
            $table->string('cooldown_mode', 16)->default('global')->after('priority');
            $table->unsignedInteger('cooldown_minutes')->nullable()->after('cooldown_mode');
            $table->string('scope', 16)->default('global')->after('cooldown_minutes');
        });

        Schema::table('rule_triggers', function (Blueprint $table) {
            $table->string('precision', 16)->default('exato')->after('match_value');
            $table->string('fuzzy_level', 8)->nullable()->after('precision');
        });

        Schema::create('rule_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auto_reply_rule_id')->constrained('auto_reply_rules')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['auto_reply_rule_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_contacts');

        Schema::table('rule_triggers', function (Blueprint $table) {
            $table->dropColumn(['precision', 'fuzzy_level']);
        });

        Schema::table('auto_reply_rules', function (Blueprint $table) {
            $table->dropColumn(['cooldown_mode', 'cooldown_minutes', 'scope']);
        });
    }
};
