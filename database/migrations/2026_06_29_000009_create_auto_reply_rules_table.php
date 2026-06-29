<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Regras gatilho -> resposta fixa (SEM IA). Escopo account.
 *
 * match_type: exact | contains | starts_with. Normalizacao (trim+lower+fold de acento)
 * e semantica de 'contains' = palavra inteira ficam no RuleMatcher. Ordem: priority asc,
 * id asc; primeira habilitada que casa vence; uma resposta. Sem match -> silencio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_reply_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('match_type', 16); // exact | contains | starts_with
            $table->string('match_value');
            $table->text('response_text');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('priority')->default(0);
            $table->timestamps();

            $table->index(['account_id', 'enabled', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_reply_rules');
    }
};
