<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Variaveis V-1 — placeholders configuraveis na UI. Aditivo.
 *
 * types: static ({valor}) | horario (faixas [{inicio,fim,valor}] + valor_padrao;
 * faixas podem cruzar meia-noite) | dia_semana ({seg..dom} parciais + valor_padrao).
 * Resolucao SEMPRE em America/Sao_Paulo, SO no envio (nunca antes do modelo de IA).
 *
 * is_system: a {saudacao} migra pra ca com default IDENTICO ao codigo atual
 * (nao renomeia, nao exclui, nao desativa — so edita textos/faixas). {nome}/
 * {data}/{hora} seguem nativas; {senha:} segue exclusiva do cofre.
 *
 * GUARDAS (no writer): valor NUNCA contem {senha:}/segredo nem outra variavel
 * (um nivel, sem recursao); nomes reservados bloqueados; slug [a-z0-9_]+.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);          // slug [a-z0-9_]+
            $table->string('type', 12);          // static | horario | dia_semana
            $table->json('config');
            $table->boolean('is_system')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['account_id', 'name']);
        });

        // Migra a {saudacao} pras contas EXISTENTES (default identico ao codigo).
        foreach (DB::table('accounts')->pluck('id') as $accountId) {
            app(\App\Variables\VariableProvisioner::class)->ensureSystemVariables((int) $accountId);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('variables');
    }
};
