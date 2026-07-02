<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CH-2 — janela de 24h da Meta por CONTATO+CANAL (aditiva). Sem backfill: a
 * janela e um estado VIVO (reabre a cada inbound); historico nao ajuda — na
 * Evolution ela nem e consultada (mensagem livre sempre pode).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_channel_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_inbound_at');
            $table->timestamps();

            $table->unique(['contact_id', 'channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_channel_windows');
    }
};
