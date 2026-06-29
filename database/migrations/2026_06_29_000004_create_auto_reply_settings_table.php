<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * auto_reply_settings — 1 linha por account. Freios flipaveis (kill switch, janela,
 * tetos). E TABELA (nao .env) porque o kill switch precisa flipar INSTANTANEO,
 * sem config:clear/restart. Defaults aprovados pelo Fabio (Camada 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_reply_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained()->cascadeOnDelete();

            // Kill switch da AUTO-resposta (NAO afeta envio manual). Default OFF.
            $table->boolean('enabled')->default(false);

            // Janela de horario (America/Sao_Paulo).
            $table->time('window_start')->default('08:00:00');
            $table->time('window_end')->default('20:00:00');

            // Tetos protetivos (valem tambem pro envio manual).
            $table->unsignedInteger('min_interval_seconds')->default(30);
            $table->unsignedInteger('per_minute_cap')->default(4);
            $table->unsignedInteger('per_day_cap')->default(40);

            // Rate por contato (so auto-resposta): 1 a cada 30 min.
            $table->unsignedInteger('contact_rate_seconds')->default(1800);

            // Delay humano (aplicado no enfileiramento da auto-resposta — Fatia 3).
            $table->unsignedInteger('delay_min_seconds')->default(3);
            $table->unsignedInteger('delay_max_seconds')->default(15);

            // Toggles.
            $table->boolean('skip_groups')->default(true);
            $table->boolean('warmup_enabled')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_reply_settings');
    }
};
