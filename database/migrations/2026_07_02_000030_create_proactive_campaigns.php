<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proativas P-2 — campanhas com GATE HUMANO estrutural. Aditivo.
 *
 * Ciclo: draft (edita tudo) -> previewed (lista EXATA resolvida e mostrada) ->
 * approved (SNAPSHOT congelado: targets criados + agenda materializada; mensagem
 * e publico TRAVADOS) -> cancelled. running/done/paused chegam na P-3 (disparo).
 * NADA dispara nesta fatia.
 *
 * campaign_targets: snapshot do publico aprovado, um por contato (UNIQUE), com
 * scheduled_at distribuido na janela proativa com jitter (D5: 3-15min).
 * pending | skipped agora; sent/failed/processing sao da P-3 (sent_at ja criado
 * aqui pra evitar migration dupla).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proactive_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('message'); // template: placeholders comuns ok; {senha:} PROIBIDO (save + guard)
            $table->string('audience_type', 16); // tags | coluna_kanban | contatos
            $table->json('audience_config');     // {tag_ids:[]} | {column_id:int} | {contact_ids:[]}
            $table->string('status', 16)->default('draft'); // draft|previewed|approved|cancelled (P-3: running|done|paused)
            $table->timestamp('start_at')->nullable();      // null = assim que aprovada, dentro da janela
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['account_id', 'status']);
        });

        Schema::create('campaign_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('proactive_campaigns')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('pending'); // pending|skipped (P-3: processing|sent|failed)
            $table->string('skip_reason', 64)->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable(); // preenchido so na P-3
            $table->timestamps();

            $table->unique(['campaign_id', 'contact_id']);
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_targets');
        Schema::dropIfExists('proactive_campaigns');
    }
};
