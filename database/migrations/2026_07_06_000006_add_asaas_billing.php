<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fatia 26 — billing Asaas. ADITIVA:
 *
 *  accounts: ids do Asaas (customer/subscription — NUNCA dado de cartao; o
 *  checkout e HOSPEDADO no Asaas) + marcos da maquina de estados
 *  (overdue_since pro corte overdue->suspended; suspended_at pra auditoria).
 *  Contas existentes: tudo null — 'legacy' (active sem Asaas), IMUNES por
 *  construcao (nenhum caminho da maquina as alcanca: o webhook resolve por
 *  subscription id, que elas nao tem; o sweep so olha status trial/overdue).
 *
 *  billing_webhook_events: DEDUP por event_id (unique) — o Asaas entrega
 *  "at least once" (retry ate a fila interromper apos 15 falhas); o mesmo
 *  evento reentregue vira no-op. Tambem e a trilha de auditoria do billing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('asaas_customer_id', 40)->nullable()->after('trial_ends_at')->index();
            $table->string('asaas_subscription_id', 40)->nullable()->after('asaas_customer_id')->index();
            $table->timestamp('overdue_since')->nullable()->after('asaas_subscription_id');
            $table->timestamp('suspended_at')->nullable()->after('overdue_since');
        });

        Schema::create('billing_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 64)->unique(); // dedup: retry do Asaas = no-op
            $table->string('event', 64);
            $table->string('payment_id', 40)->nullable();
            $table->string('subscription_id', 40)->nullable()->index();
            $table->string('customer_id', 40)->nullable();
            $table->unsignedBigInteger('account_id')->nullable()->index(); // resolvida no JOB
            $table->string('status', 20)->default('pending'); // pending|processed|ignored
            $table->json('payload')->nullable(); // cobranca do Asaas: NAO contem dado de cartao
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Remove SO o que esta migration adicionou.
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['asaas_customer_id', 'asaas_subscription_id', 'overdue_since', 'suspended_at']);
        });
        Schema::dropIfExists('billing_webhook_events');
    }
};
