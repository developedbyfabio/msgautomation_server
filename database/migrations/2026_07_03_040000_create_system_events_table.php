<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Prompt 02 — timeline de eventos do sistema (pagina /logs). ADITIVA.
// account_id NULL = evento GLOBAL do servidor (erro de sistema sem conta);
// ref = chave de idempotencia (ex.: status-failed:{wamid}) contra re-entrega.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);              // envio_falhou | canal | erro_sistema
            $table->string('level', 16)->default('info'); // info | warning | error
            $table->string('title', 200);
            $table->text('detail')->nullable();      // JSON (code/title/recipient) — nunca segredo
            $table->string('ref', 120)->nullable()->unique();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['account_id', 'occurred_at']);
            $table->index(['type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        // Aditiva por politica; drop so em dev com aval humano.
        Schema::dropIfExists('system_events');
    }
};
