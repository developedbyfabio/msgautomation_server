<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Prompt 05 — anexos parte B (documentos). ADITIVA: nome ORIGINAL do arquivo
// (o path no disco e uuid — o card do documento mostra o nome real).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_reply_logs', function (Blueprint $table) {
            $table->string('media_name')->nullable()->after('media_mime');
        });
    }

    public function down(): void
    {
        Schema::table('auto_reply_logs', function (Blueprint $table) {
            $table->dropColumn('media_name');
        });
    }
};
