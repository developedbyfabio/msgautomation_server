<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Camada 3 (IA) Fatia 3 — fila de aprovacao humana. Aditivo/nao-destrutivo.
 *
 * Toda decisao `escalou` da IA (baixa confianca, tema de aprovacao, modo aprovacao,
 * segredo na resposta, conteudo high) vira UMA pendencia revisavel no /revisao.
 * NADA e enviado sem clique humano. `suggested_response` guarda a melhor sugestao
 * disponivel (resposta da regra candidata ou resposta fundamentada da base) com
 * placeholders INTACTOS ({senha:nome} etc.) — o valor real NUNCA e persistido aqui;
 * resolucao so no envio (Sender), como em toda resposta.
 *
 * status: pending | approved (enviada como veio) | edited (enviada com ajuste) |
 * rejected (ignorada) | expired (velha demais; nada enviado).
 * origin: regra | base (espelha ai_decisions.origem; 'ia' reservado pra escalas
 * futuras sem origem material).
 *
 * incoming_message_id UNIQUE = idempotencia dura (uma pendencia por mensagem,
 * protege corrida/re-entrega). sent_auto_reply_log_id liga ao log do envio aprovado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('incoming_message_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('ai_decision_id')->nullable()->constrained('ai_decisions')->nullOnDelete();
            $table->string('remote_jid');
            $table->text('suggested_response')->nullable(); // nem toda escala tem sugestao
            $table->string('origin', 8)->default('regra');  // regra | base | ia
            $table->string('reason', 64)->nullable();       // baixa_confianca | tema_aprovacao | ...
            $table->string('intent')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('status', 16)->default('pending'); // pending|approved|edited|rejected|expired
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('sent_auto_reply_log_id')->nullable()->constrained('auto_reply_logs')->nullOnDelete();
            $table->timestamps();

            $table->index(['account_id', 'status']);
            $table->index(['account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_approvals');
    }
};
