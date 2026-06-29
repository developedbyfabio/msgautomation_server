<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S4 — cache do nome (subject) de grupos. Escopo account. Resolvido UMA vez por
 * grupo (job em background), atualizado de vez em quando. Display apenas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('remote_jid');           // ...@g.us
            $table->string('subject')->nullable();   // nome do grupo
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'remote_jid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('groups');
    }
};
