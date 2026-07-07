<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Servidores S3 (canal) — roteamento de destinatarios + marcas de notificacao.
 * ADITIVA.
 *
 * server_alert_contacts: QUEM recebe O QUE. Escopo por conta (A1). Filtro por
 * severidade (min_level: warning recebe warning+critical; critical so critical)
 * e por alvo (server_id especifico OU grupo OU — ambos NULL — todos os
 * servidores da conta). email nullable = fallback quando o WhatsApp falha (B4).
 *
 * server_incidents ganha:
 *  - notified_level: nivel para o qual JA saiu notificacao de abertura. Unifica
 *    firing (null->nivel) e escalada (warning->critical): notifica-se quando
 *    notified_level != level. Base da idempotencia do envio ON.
 *  - last_notified_at: hora do ultimo envio (qualquer) — base do cooldown de
 *    re-notificacao (so critical nao-reconhecido repete).
 * (notified_firing_at/notified_resolved_at seguem existindo: trilha do modo
 * silencioso S2 e auditoria.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_alert_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->nullable()->constrained('servers')->cascadeOnDelete();
            $table->string('grupo', 60)->nullable();      // alvo por grupo (quando server_id NULL)
            $table->string('name', 100);
            $table->string('phone', 30);                  // destino WhatsApp (digitos)
            $table->string('email', 150)->nullable();     // fallback B4
            $table->string('min_level', 10)->default('warning'); // warning|critical
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['account_id', 'enabled']);
        });

        Schema::table('server_incidents', function (Blueprint $table) {
            $table->string('notified_level', 10)->nullable()->after('notified_resolved_at');
            $table->timestamp('last_notified_at')->nullable()->after('notified_level');
        });
    }

    public function down(): void
    {
        Schema::table('server_incidents', function (Blueprint $table) {
            $table->dropColumn(['notified_level', 'last_notified_at']);
        });
        Schema::dropIfExists('server_alert_contacts');
    }
};
