<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Politica de resposta (Fatia 3):
 *  - allowlist (DEFAULT, aprovado): responde SO contatos com auto_reply_mode = on.
 *  - all: responde todos, EXCETO auto_reply_mode = off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_reply_settings', function (Blueprint $table) {
            $table->string('reply_policy', 16)->default('allowlist')->after('enabled');
        });
    }

    public function down(): void
    {
        Schema::table('auto_reply_settings', function (Blueprint $table) {
            $table->dropColumn('reply_policy');
        });
    }
};
