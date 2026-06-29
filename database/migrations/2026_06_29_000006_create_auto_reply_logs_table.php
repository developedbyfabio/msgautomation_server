<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * auto_reply_logs — registro de toda tentativa de envio (manual e auto).
 *
 * Idempotencia da AUTO-resposta: indice unico em incoming_message_id (uma resposta
 * por mensagem recebida). Em MySQL/sqlite, UNIQUE permite multiplos NULL -> envios
 * MANUAIS (sem incoming_message_id) nao colidem entre si.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_reply_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('incoming_message_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('rule_id')->nullable();
            $table->string('remote_jid');
            $table->string('mode', 16); // 'manual' | 'auto'
            $table->text('response_text');
            $table->string('status', 16); // 'sent' | 'blocked' | 'failed'
            $table->string('motivo', 64)->nullable(); // qual freio parou, ou erro
            $table->string('provider_message_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'remote_jid']);
            $table->index(['account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_reply_logs');
    }
};
