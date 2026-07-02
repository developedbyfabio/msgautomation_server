<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kanban K-2 — marca as regras DEFAULT do provisioner (aditivo). Na UI, editar/
 * desativar uma default pede confirmacao (explica o efeito no movimento padrao).
 * Todas as regras existentes ate aqui foram criadas pelo provisioner -> default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('board_rules', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('active');
        });

        DB::table('board_rules')->update(['is_default' => true]);
    }

    public function down(): void
    {
        Schema::table('board_rules', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
