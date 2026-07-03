<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Prompt 04 — anexos parte A (imagens). ADITIVA: envio com midia guarda o
// caminho no disco privado (media/{conta}/...) e o mime; texto vira caption.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_reply_logs', function (Blueprint $table) {
            $table->string('media_path')->nullable()->after('response_text');
            $table->string('media_mime', 64)->nullable()->after('media_path');
        });
    }

    public function down(): void
    {
        Schema::table('auto_reply_logs', function (Blueprint $table) {
            $table->dropColumn(['media_path', 'media_mime']);
        });
    }
};
