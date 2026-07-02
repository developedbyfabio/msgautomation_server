<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kanban K-1 (N7 do doc 09) — modelo do "Kanban dirigido por conversa". Aditivo.
 *
 * O Kanban e OBSERVADOR PURO: assiste eventos do pipeline e move cards. Nunca envia,
 * nunca decide resposta. D4 aprovada: 1 board default por conta, colunas Novo /
 * Em atendimento / Aguardando resposta / Resolvido / Reativacao (slug estavel pra
 * regras/UI; nome editavel na K-2).
 *
 * - cards: UM por contato por board (unique). Grupos ficam fora (como no robo).
 * - card_transitions: historico completo; from/to sem FK dura (colunas podem ser
 *   remodeladas na K-2 sem apagar historico).
 * - board_rules: "evento X (condicoes minimas em JSON) -> coluna Y", first-match por
 *   position. Estrutura pronta pra UI da K-2 editar.
 *
 * A migration provisiona o board default pras contas EXISTENTES (producao ganha sem
 * seeder); contas futuras ganham via hook Account::created (BoardProvisioner).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['account_id', 'is_default']);
        });

        Schema::create('board_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 32);   // referencia ESTAVEL (novo, em_atendimento, ...)
            $table->string('name');       // rotulo exibido (editavel na K-2)
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['board_id', 'slug']);
        });

        Schema::create('cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            // Sem cascade: apagar coluna com cards deve falhar (K-2 realoca antes).
            $table->foreignId('column_id')->constrained('board_columns');
            $table->timestamp('last_interaction_at')->nullable();
            $table->string('last_direction', 3)->nullable(); // in | out
            $table->timestamps();

            $table->unique(['board_id', 'contact_id']);
            $table->index(['account_id', 'column_id']);
        });

        Schema::create('card_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained()->cascadeOnDelete();
            // Sem FK dura: historico sobrevive a remodelagem de colunas/regras.
            $table->unsignedBigInteger('from_column_id')->nullable(); // null = card criado
            $table->unsignedBigInteger('to_column_id');
            $table->string('cause', 16); // regra | manual (K-2) | tempo (P-fatias)
            $table->unsignedBigInteger('board_rule_id')->nullable();
            $table->string('event_type', 32)->nullable();  // mensagem_recebida, resposta_enviada, ...
            $table->unsignedBigInteger('event_ref')->nullable(); // id do incoming/log/decisao/sessao
            $table->timestamps();

            $table->index('card_id');
            // Idempotencia de re-entrega: um mesmo evento nunca gera 2 transicoes no card.
            $table->unique(['card_id', 'event_type', 'event_ref'], 'card_transitions_event_unique');
        });

        Schema::create('board_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 32);
            $table->json('conditions')->nullable(); // {"card":"absent"} | {"card_in_column":"resolvido"} | {"not_in_column":"em_atendimento"}
            $table->foreignId('to_column_id')->constrained('board_columns');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['account_id', 'event_type']);
        });

        // Provisiona o board default (colunas D4 + regras minimas) pras contas existentes.
        foreach (DB::table('accounts')->pluck('id') as $accountId) {
            app(\App\Kanban\BoardProvisioner::class)->ensureDefaultBoard((int) $accountId);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('board_rules');
        Schema::dropIfExists('card_transitions');
        Schema::dropIfExists('cards');
        Schema::dropIfExists('board_columns');
        Schema::dropIfExists('boards');
    }
};
