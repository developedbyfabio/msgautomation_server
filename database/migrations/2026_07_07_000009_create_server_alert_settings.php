<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Servidores — preferencias de alerta POR CONTA. ADITIVA.
 *
 * group_separator: o texto que separa os avisos quando varios vao na MESMA
 * mensagem de WhatsApp (agrupamento anti-tempestade). NULL = default sensato
 * ("\n", uma quebra de linha). O dono pode por linha em branco ("\n\n"), uma
 * linha de tracos, asteriscos, etc.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_alert_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('group_separator', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_alert_settings');
    }
};
