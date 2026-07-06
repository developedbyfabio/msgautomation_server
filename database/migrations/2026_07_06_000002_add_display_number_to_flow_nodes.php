<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fatia 17 — numeracao de exibicao POR FLUXO. A PK (flow_nodes.id) e
 * auto-increment GLOBAL da tabela e vazava pra UI ("fluxo 5 com no #20");
 * ela continua sendo a chave de dados (FKs de opcoes/sessoes/parents
 * intocadas) — display_number e SO a identidade de exibicao.
 *
 * ADITIVO: coluna nullable + backfill idempotente (numera 1..N na ordem de id,
 * preenchendo APENAS onde null — re-rodar e no-op; numeros ja atribuidos nunca
 * sao reordenados) + unique composto (flow_id, display_number).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_nodes', function (Blueprint $table) {
            $table->unsignedInteger('display_number')->nullable()->after('flow_id');
        });

        // Backfill idempotente: por fluxo, continua do max ja atribuido (0 na
        // primeira execucao) e numera os null na ordem de criacao (id).
        $pendentes = DB::table('flow_nodes')->whereNull('display_number')
            ->orderBy('id')->get(['id', 'flow_id'])->groupBy('flow_id');
        foreach ($pendentes as $flowId => $rows) {
            $n = (int) DB::table('flow_nodes')->where('flow_id', $flowId)->max('display_number');
            foreach ($rows as $row) {
                DB::table('flow_nodes')->where('id', $row->id)->update(['display_number' => ++$n]);
            }
        }

        Schema::table('flow_nodes', function (Blueprint $table) {
            $table->unique(['flow_id', 'display_number']);
        });
    }

    public function down(): void
    {
        // Remove SO o que esta migration adicionou.
        Schema::table('flow_nodes', function (Blueprint $table) {
            $table->dropUnique(['flow_id', 'display_number']);
            $table->dropColumn('display_number');
        });
    }
};
