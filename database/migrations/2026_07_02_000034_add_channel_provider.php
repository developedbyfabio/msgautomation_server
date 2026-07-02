<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CH-1 — canal multi-provedor (doc 10), ADITIVO:
 *  - channels.provider: 'evolution' | 'cloud_api' (default evolution; backfill
 *    dos canais existentes — nada muda de comportamento);
 *  - channels.credentials: credenciais POR CANAL, cifradas em repouso (cast
 *    encrypted:array no model). NAO migramos valores do env aqui — MT-2 preenche
 *    e remove o fallback; nesta fatia o accessor le vazio -> cai no env.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->string('provider', 16)->default('evolution')->after('instance');
            $table->text('credentials')->nullable()->after('provider');
        });

        DB::table('channels')->update(['provider' => 'evolution']);
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['provider', 'credentials']);
        });
    }
};
