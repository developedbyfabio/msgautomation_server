<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Servidores S1 — inventario de servidores monitorados (ferramenta INTERNA do
 * dono). ADITIVA: tabela nova, nada existente e tocado.
 *
 * NAO ha tabela de historico de metricas (decisao da Fase 0): as amostras vivem
 * num buffer RECENTE e EFEMERO (cache/Redis, trim+TTL — App\Servers\MetricsBuffer).
 * Aqui fica so o ESTADO CORRENTE:
 *  - last_seen_at: ultima ingestao valida — base DURAVEL do watchdog futuro (S2);
 *    sobrevive a flush/restart do Redis por estar no MySQL.
 *  - last_sample: ultima amostra (JSON) — cards do painel (S4) nao dependem do
 *    buffer existir. Nao e historico: uma linha, sempre sobrescrita.
 *
 * Token do agente: o valor em claro fica SO no Cofre (secrets, cifra dedicada);
 * agent_token_secret_ref guarda o NOME do segredo e agent_token_hash o sha256
 * do token — lookup O(1) indexado na ingestao (nunca o claro na tabela).
 * Nullable: servidor recem-criado ganha os dois no mesmo request (AgentToken);
 * sem hash, nenhum token casa — o servidor simplesmente nao ingere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('host', 150)->nullable();          // hostname/IP descritivo
            $table->string('os', 20)->default('linux');       // v1: linux (windows futuro)
            $table->string('grupo', 60)->nullable();          // agrupamento (regras por grupo no futuro)
            $table->string('agent_token_secret_ref', 120)->nullable(); // nome do segredo no Cofre
            $table->string('agent_token_hash', 64)->nullable()->unique(); // sha256 do token
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_seen_at')->nullable();    // base do watchdog (S2)
            $table->json('last_sample')->nullable();          // estado corrente, NAO historico
            $table->timestamps();

            $table->unique(['account_id', 'name']);
        });
    }

    public function down(): void
    {
        // Remove SO o que esta migration adicionou.
        Schema::dropIfExists('servers');
    }
};
