<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * contacts — escopo account. Guarda o opt-out por contato (nunca AUTO-responder).
 * Opt-out NAO bloqueia envio manual (intervencao humana e override).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('remote_jid');
            $table->string('push_name')->nullable();
            $table->boolean('auto_reply_opt_out')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'remote_jid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
