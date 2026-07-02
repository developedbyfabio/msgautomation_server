<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tags T-1 (N9 do doc 09) — segmentacao por contato. Aditivo.
 *
 * - tags: por conta, nome unico, cor da paleta de badges.
 * - contact_tag: pivo com ORIGEM rastreada (manual | board_rule | ai_intent) e
 *   UNIQUE (contato, tag) = re-aplicacao/re-entrega idempotente no banco.
 * - board_rules ganham ACAO: move_column (default; first-match como sempre) |
 *   add_tag | remove_tag (CUMULATIVAS: todas as que casam aplicam). Regras
 *   existentes viram move_column pelo default (comportamento identico);
 *   to_column_id vira nullable (acoes de tag nao tem coluna destino).
 * - rule_tag / flow_tag: escopo por tag em regras e fluxos ("Contatos com tag",
 *   casa quem tem QUALQUER uma; avaliado na hora do match).
 *
 * Tags NUNCA enviam nada — segmentam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('color', 16)->default('zinc');
            $table->timestamps();

            $table->unique(['account_id', 'name']);
        });

        Schema::create('contact_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->string('origin', 16)->default('manual'); // manual | board_rule | ai_intent
            $table->string('origin_ref', 120)->nullable();   // id da board_rule ou nome do intent
            $table->timestamps();

            $table->unique(['contact_id', 'tag_id']); // idempotencia dura
        });

        Schema::create('rule_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auto_reply_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['auto_reply_rule_id', 'tag_id']);
        });

        Schema::create('flow_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['flow_id', 'tag_id']);
        });

        Schema::table('board_rules', function (Blueprint $table) {
            $table->string('action_type', 16)->default('move_column')->after('conditions');
            $table->foreignId('tag_id')->nullable()->after('to_column_id')->constrained('tags')->nullOnDelete();
            $table->unsignedBigInteger('to_column_id')->nullable()->change(); // acoes de tag nao tem coluna
        });

        // Explicito (alem do default): regras existentes = move_column (identico).
        DB::table('board_rules')->whereNull('action_type')->orWhere('action_type', '')->update(['action_type' => 'move_column']);
    }

    public function down(): void
    {
        Schema::table('board_rules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tag_id');
            $table->dropColumn('action_type');
        });
        Schema::dropIfExists('flow_tag');
        Schema::dropIfExists('rule_tag');
        Schema::dropIfExists('contact_tag');
        Schema::dropIfExists('tags');
    }
};
