<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MATCH-1 — aditivo:
 *  - normalized_text nos gatilhos (regra e fluxo), com BACKFILL idempotente via
 *    o normalizador unico (regex fica NULL — casa contra o texto cru);
 *  - unmatched_messages: o log de sem-match (silencio elegivel vira oportunidade
 *    de regra nova); prune >30d por comando agendado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rule_triggers', function (Blueprint $table) {
            $table->string('normalized_text', 500)->nullable()->after('match_value')->index();
        });
        Schema::table('flow_triggers', function (Blueprint $table) {
            $table->string('normalized_text', 500)->nullable()->after('match_value');
        });

        Schema::create('unmatched_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('text', 200);
            $table->timestamps();

            $table->index(['account_id', 'created_at']);
        });

        // Backfill idempotente (recalcula todos; observer mantem daqui em diante).
        foreach (['rule_triggers', 'flow_triggers'] as $tabela) {
            foreach (DB::table($tabela)->get(['id', 'match_type', 'match_value']) as $t) {
                DB::table($tabela)->where('id', $t->id)->update([
                    'normalized_text' => $t->match_type === 'regex'
                        ? null
                        : \App\Whatsapp\TextNormalizer::normalize((string) $t->match_value),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('unmatched_messages');
        Schema::table('rule_triggers', fn (Blueprint $t) => $t->dropColumn('normalized_text'));
        Schema::table('flow_triggers', fn (Blueprint $t) => $t->dropColumn('normalized_text'));
    }
};
