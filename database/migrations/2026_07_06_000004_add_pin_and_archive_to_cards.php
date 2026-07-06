<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fatia 20 — Kanban: (a) pinned_until_reply — card movido POR HUMANO nao e
 * sobrescrito pelas transicoes automaticas ate a PROXIMA mensagem do contato;
 * (b) archived_at — "arquivar parados" REVERSIVEL (soft, NUNCA delete fisico):
 * card arquivado some do board e AUTO-RESTAURA quando o contato escreve.
 * ADITIVA: dois campos com default (backfill trivial: false/null).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->boolean('pinned_until_reply')->default(false)->after('last_direction');
            $table->timestamp('archived_at')->nullable()->after('pinned_until_reply');
        });
    }

    public function down(): void
    {
        // Remove SO o que esta migration adicionou.
        Schema::table('cards', function (Blueprint $table) {
            $table->dropColumn(['pinned_until_reply', 'archived_at']);
        });
    }
};
