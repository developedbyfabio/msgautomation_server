<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S7 — regras avancadas, NAO-destrutivo.
 *
 * Schema proposto: auto_reply_rules (mantida) + DUAS tabelas filhas:
 *   - rule_triggers:  varios gatilhos por regra (cada um com seu match_type).
 *                     match_type ganha o tipo 'regex' (avancado, validado/protegido).
 *   - rule_responses: varias respostas por regra (escolha aleatoria no envio,
 *                     ajuda anti-ban). Placeholders ({nome}, saudacao) no envio.
 *
 * As colunas antigas de auto_reply_rules (match_type/match_value/response_text)
 * NAO sao apagadas nem alteradas: passam a ser um "cache" denormalizado do 1o
 * gatilho/1a resposta (back-compat + fallback). O motor le as tabelas filhas e
 * cai pro legado quando nao ha filhas.
 *
 * Backfill: cada regra existente vira 1 gatilho + 1 resposta (idempotente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rule_triggers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auto_reply_rule_id')->constrained('auto_reply_rules')->cascadeOnDelete();
            $table->string('match_type', 16); // exact | contains | starts_with | regex
            $table->string('match_value');
            $table->timestamps();

            $table->index('auto_reply_rule_id');
        });

        Schema::create('rule_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auto_reply_rule_id')->constrained('auto_reply_rules')->cascadeOnDelete();
            $table->text('response_text');
            $table->timestamps();

            $table->index('auto_reply_rule_id');
        });

        // Migra as regras atuais (gatilho unico / resposta unica) pra nova estrutura,
        // SEM apagar nada. Idempotente: so insere se a regra ainda nao tem filhas.
        $agora = now();
        foreach (DB::table('auto_reply_rules')->get() as $rule) {
            $temTrigger = DB::table('rule_triggers')->where('auto_reply_rule_id', $rule->id)->exists();
            if (! $temTrigger && $rule->match_value !== null && $rule->match_value !== '') {
                DB::table('rule_triggers')->insert([
                    'auto_reply_rule_id' => $rule->id,
                    'match_type' => $rule->match_type ?: 'contains',
                    'match_value' => $rule->match_value,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ]);
            }

            $temResposta = DB::table('rule_responses')->where('auto_reply_rule_id', $rule->id)->exists();
            if (! $temResposta && $rule->response_text !== null && $rule->response_text !== '') {
                DB::table('rule_responses')->insert([
                    'auto_reply_rule_id' => $rule->id,
                    'response_text' => $rule->response_text,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Nao-destrutivo: os dados originais seguem em auto_reply_rules.
        Schema::dropIfExists('rule_responses');
        Schema::dropIfExists('rule_triggers');
    }
};
