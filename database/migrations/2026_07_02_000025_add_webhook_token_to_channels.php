<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * MT-0 — token de webhook POR CANAL. Aditivo.
 *
 * A rota nova /webhook/evolution/{token} autentica pelo token do canal; a rota
 * atual (header X-Webhook-Secret global) segue valendo como RETROCOMPAT (a
 * Evolution NAO e reconfigurada nesta fatia — migracao da URL e passo da MT-2,
 * documentado no doc 09). Popula token pros canais existentes (UPDATE com WHERE
 * por id, reversivel via down).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->string('webhook_token', 64)->nullable()->unique()->after('instance');
        });

        // Gera token unico pra cada canal existente (hoje: 1).
        foreach (DB::table('channels')->whereNull('webhook_token')->pluck('id') as $id) {
            DB::table('channels')->where('id', $id)->update(['webhook_token' => Str::random(48)]);
        }
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('webhook_token');
        });
    }
};
