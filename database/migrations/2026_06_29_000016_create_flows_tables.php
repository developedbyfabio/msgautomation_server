<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fatia A — motor de fluxos (menus condicionais, determinístico). Aditivo/nao-
 * destrutivo, escopo account. Referencias entre NOS (root/parent/next/current) sao
 * bigints nullable SEM FK constraint (auto-referencia + arvore -> evita cascata
 * problematica); FKs "duras" so em flow_id/account_id/contact_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('enabled')->default(false);
            $table->string('scope', 16)->default('global');     // global | contatos
            $table->unsignedInteger('timeout_seconds')->default(600); // inatividade ate expirar
            $table->string('invalid_message')->nullable();      // texto de "opcao invalida"
            $table->unsignedBigInteger('root_node_id')->nullable();
            $table->timestamps();
        });

        Schema::create('flow_triggers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained('flows')->cascadeOnDelete();
            $table->string('match_type', 16);   // exact | contains | starts_with | regex
            $table->string('match_value');
            $table->string('precision', 16)->default('exato'); // exato | tolerante
            $table->string('fuzzy_level', 8)->nullable();
            $table->timestamps();
            $table->index('flow_id');
        });

        Schema::create('flow_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained('flows')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['flow_id', 'contact_id']);
        });

        Schema::create('flow_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained('flows')->cascadeOnDelete();
            $table->unsignedBigInteger('parent_node_id')->nullable();
            $table->string('kind', 16)->default('menu'); // menu | final
            $table->text('message');
            $table->unsignedInteger('ordem')->default(0);
            $table->timestamps();
            $table->index('flow_id');
        });

        Schema::create('flow_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_node_id')->constrained('flow_nodes')->cascadeOnDelete();
            $table->string('input', 32);   // o que o contato digita (ex.: "1")
            $table->string('label');       // rotulo exibido (ex.: "1 - Suporte")
            $table->unsignedBigInteger('next_node_id')->nullable();
            $table->unsignedInteger('ordem')->default(0);
            $table->timestamps();
            $table->index('flow_node_id');
        });

        Schema::create('flow_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_id')->constrained('flows')->cascadeOnDelete();
            $table->string('remote_jid');
            $table->unsignedBigInteger('current_node_id')->nullable();
            $table->string('status', 16)->default('active'); // active | completed | expired | cancelled
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['account_id', 'remote_jid', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_sessions');
        Schema::dropIfExists('flow_options');
        Schema::dropIfExists('flow_nodes');
        Schema::dropIfExists('flow_contacts');
        Schema::dropIfExists('flow_triggers');
        Schema::dropIfExists('flows');
    }
};
