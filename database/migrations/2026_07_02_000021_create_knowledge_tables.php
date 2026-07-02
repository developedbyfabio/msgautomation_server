<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Camada 3 (IA) Fatia 2 — base de conhecimento. Aditivo/nao-destrutivo.
 *
 * knowledge: entradas que a IA pode usar pra responder no modo `conhecimento`.
 *  - sensitivity: low | medium | high. REGRA DURA: conteudo `high` NUNCA vai pro
 *    modelo e NUNCA e respondido direto (escala pra revisao humana — Fatia 3).
 *    So low/medium entram no prompt. Default medium (conservador).
 *  - content pode conter placeholders ({senha:nome}, {nome}, ...) — resolvidos
 *    LOCALMENTE no envio, nunca expandidos antes do modelo.
 *
 * knowledge_contacts: permissao por contato. SEM linhas = entrada disponivel pra
 * QUALQUER contato com IA ligada em modo conhecimento; COM linhas = so os listados.
 *
 * Defaults preservam o comportamento atual: tabela nasce vazia e o modo
 * `conhecimento` e opt-in por contato — nada muda ate o Fabio ligar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('content');
            $table->string('sensitivity', 8)->default('medium'); // low | medium | high
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['account_id', 'active']);
        });

        Schema::create('knowledge_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_id')->constrained('knowledge')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['knowledge_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_contacts');
        Schema::dropIfExists('knowledge');
    }
};
