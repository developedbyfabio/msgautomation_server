<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cofre de senhas (escopo account). O VALOR vai cifrado em repouso (value_encrypted),
 * com chave dedicada (SECRETS_KEY). nome/categoria/notes NAO sao secretos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secrets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('nome');                 // label nao-secreto (ex.: wifi_pais)
            $table->text('value_encrypted');         // valor CIFRADO (nunca em claro)
            $table->string('categoria')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'nome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secrets');
    }
};
