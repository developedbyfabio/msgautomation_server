<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Fatia 15 — slug ESTAVEL por entrada de conhecimento, pro token {kb:slug}.
 * ADITIVO: coluna nullable + backfill idempotente (preenche SO slug null, a
 * partir do titulo, com sufixo -2/-3 em colisao POR CONTA) + unique composto
 * (account_id, slug). Nenhum dado existente e alterado alem do preenchimento.
 * O slug e gerado na CRIACAO (hook do model) e NUNCA muda no rename — renomear
 * titulo nao quebra referencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge', function (Blueprint $table) {
            $table->string('slug', 80)->nullable()->after('title');
        });

        // Backfill idempotente: so linhas com slug NULL (re-rodar seria no-op).
        $usados = []; // account_id => [slug => true] (inclui os ja preenchidos)
        foreach (DB::table('knowledge')->whereNotNull('slug')->get(['account_id', 'slug']) as $row) {
            $usados[$row->account_id][$row->slug] = true;
        }
        foreach (DB::table('knowledge')->whereNull('slug')->orderBy('id')->get(['id', 'account_id', 'title']) as $row) {
            $base = Str::slug(Str::limit((string) $row->title, 70, ''), '-') ?: 'conhecimento';
            $slug = $base;
            $n = 2;
            while (isset($usados[$row->account_id][$slug])) {
                $slug = "{$base}-{$n}";
                $n++;
            }
            $usados[$row->account_id][$slug] = true;
            DB::table('knowledge')->where('id', $row->id)->update(['slug' => $slug]);
        }

        Schema::table('knowledge', function (Blueprint $table) {
            $table->unique(['account_id', 'slug']);
        });
    }

    public function down(): void
    {
        // Remove SO o que esta migration adicionou (a coluna slug e seu indice).
        // Nenhum dado pre-existente e afetado.
        Schema::table('knowledge', function (Blueprint $table) {
            $table->dropUnique(['account_id', 'slug']);
            $table->dropColumn('slug');
        });
    }
};
