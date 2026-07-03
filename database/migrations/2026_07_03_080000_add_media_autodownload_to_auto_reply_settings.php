<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 14 — auto-download de midia recebida como opcao POR CONTA. ADITIVA.
 * NULLABLE de proposito: null = "nao decidido na tela" -> cai no default do .env
 * (services.incoming_media.download); true/false = escolha da tela, que MANDA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_reply_settings', function (Blueprint $table) {
            $table->boolean('media_autodownload')->nullable()->after('skip_groups');
        });
    }

    public function down(): void
    {
        Schema::table('auto_reply_settings', function (Blueprint $table) {
            $table->dropColumn('media_autodownload');
        });
    }
};
