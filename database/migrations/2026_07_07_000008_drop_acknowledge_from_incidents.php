<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Servidores — remocao do conceito de "reconhecer" (ack). O ciclo do incidente
 * passa a ser SO firing -> resolved. Migracao de DADOS (compativel, nao
 * destrutiva de schema):
 *
 *  - Incidentes que estavam `acknowledged` (reconhecidos manualmente) voltam a
 *    `firing` (abertos) e limpam acknowledged_* -> retomam o ciclo normal de
 *    re-aviso pela cadencia. ATENCAO: ao subir, os alertas abertos voltam a
 *    notificar no proximo tick (nao ha mais ack pra silenciar) — comportamento
 *    desejado.
 *
 * As colunas acknowledged_at / acknowledged_by permanecem no schema (nao sao
 * dropadas — evita migracao destrutiva); o app nao as usa mais. Uma limpeza de
 * schema pode ser feita depois, com aval do dono.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('server_incidents')
            ->where('status', 'acknowledged')
            ->update([
                'status' => 'firing',
                'acknowledged_at' => null,
                'acknowledged_by' => null,
            ]);
    }

    public function down(): void
    {
        // Sem reversao de dados: o estado 'acknowledged' foi descontinuado.
        // (Nao ha o que restaurar de forma segura; a coluna segue existindo.)
    }
};
