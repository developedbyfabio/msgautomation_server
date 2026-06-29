<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * channels — instancia de WhatsApp na Evolution vinculada a uma account.
 * IMPORTANTE: nenhum segredo/token de instancia aqui. So referencia nao-sensivel
 * (nome da instance) e estado de conexao. O token/api-key vive no .env.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('instance', 100)->unique();
            $table->string('status', 32)->default('disconnected');
            $table->string('remote_jid')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
