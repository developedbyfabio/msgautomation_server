<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S4 — flag "salvo" no contato. Marca que o Fabio nomeou/adicionou o contato
 * (vs. apenas auto-populado pelas mensagens recebidas). Aditivo e nao-destrutivo:
 * so acrescenta coluna com default false; nenhum dado e tocado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->boolean('saved')->default(false)->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('saved');
        });
    }
};
