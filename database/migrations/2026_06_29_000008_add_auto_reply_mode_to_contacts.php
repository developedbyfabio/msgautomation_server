<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modo de auto-resposta POR CONTATO (Fatia 3): default | on | off.
 *
 * Substitui (sem apagar) o antigo auto_reply_opt_out. Adicionamos a coluna nova e
 * fazemos backfill: opt_out=true -> 'off'; demais -> 'default'. A coluna antiga fica
 * DEPRECIADA (a logica passa a usar auto_reply_mode).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('auto_reply_mode', 16)->default('default')->after('auto_reply_opt_out');
        });

        // Backfill a partir do opt-out existente (UPDATE com WHERE).
        DB::table('contacts')->where('auto_reply_opt_out', true)->update(['auto_reply_mode' => 'off']);
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('auto_reply_mode');
        });
    }
};
