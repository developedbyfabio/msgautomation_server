<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * incoming_messages — mensagens recebidas via webhook da Evolution.
 * Camada 1: so RECEBER e REGISTRAR (nao responde nada).
 *
 * Idempotencia: indice unico (instance, evolution_message_id). Mensagem repetida
 * (re-entrega do webhook) e ignorada sem erro — nao duplica linha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incoming_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete();
            // instance denormalizada: garante idempotencia mesmo antes de resolver o channel.
            $table->string('instance', 100);
            $table->string('evolution_message_id', 191);
            $table->string('remote_jid');
            $table->boolean('from_me')->default(false);
            $table->string('push_name')->nullable();
            $table->string('type', 64);
            $table->text('text')->nullable();
            $table->json('raw_payload');
            $table->timestamp('received_at');
            $table->timestamps();

            $table->unique(['instance', 'evolution_message_id'], 'incoming_messages_idem_unique');
            $table->index('remote_jid');
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_messages');
    }
};
