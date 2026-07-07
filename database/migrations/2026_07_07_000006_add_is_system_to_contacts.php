<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversa de sistema ("Alertas de Infraestrutura") — marca o contato de
 * sistema. ADITIVA (espelha add_saved_to_contacts). is_system=true = contato
 * de EXIBIÇÃO (agrega alertas no Atendimento), EXCLUÍDO do pipeline: não vira
 * cliente, não entra em campanha, não gera card, não conta nas métricas.
 * Contatos existentes ficam false — comportamento intacto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('saved');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('is_system');
        });
    }
};
