<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Camada 3 (IA) Fatia 1 — log de CADA decisao da IA (pra revisao e pro loop de
 * aprendizado da Camada 5). Uma linha por classificacao consultada. NAO substitui
 * auto_reply_logs (que segue registrando o ENVIO): aqui fica a DECISAO da IA.
 *
 * acao: respondeu (despachou a resposta da regra) | escalou (silenciou agora; futura
 * fila de aprovacao na Fatia 3) | silenciou (nao classificou / erro / cota / modelo disse nao).
 * matched_rule_id sem FK dura (a regra pode ser excluida sem apagar o historico).
 * NUNCA guarda valor de segredo nem a mensagem crua — so intent/confidence/motivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('incoming_message_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('matched_rule_id')->nullable();
            $table->string('remote_jid');
            $table->string('intent')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('acao', 16); // respondeu | escalou | silenciou
            $table->string('motivo', 64)->nullable();
            $table->string('model', 64)->nullable();
            $table->timestamps();

            $table->index(['account_id', 'created_at']);
            $table->index(['account_id', 'remote_jid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_decisions');
    }
};
