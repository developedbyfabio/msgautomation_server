<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MT-1 — vinculo users <-> accounts (D3: fase 1 = 1 dono por conta, schema ja
 * pronto pra N e pro papel operador — sem UI de operador ainda).
 *
 * Backfill ADITIVO e idempotente: todo usuario existente sem vinculo vira OWNER
 * da conta mais antiga (a conta 1 do Fabio) — insertOrIgnore, nada sobrescrito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16)->default('owner'); // owner | operador (D3: operador sem UI na fase 1)
            $table->timestamps();

            $table->unique(['account_id', 'user_id']);
        });

        $conta = DB::table('accounts')->orderBy('id')->value('id');
        if ($conta !== null) {
            foreach (DB::table('users')->pluck('id') as $userId) {
                DB::table('account_user')->insertOrIgnore([
                    'account_id' => $conta,
                    'user_id' => $userId,
                    'role' => 'owner',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('account_user');
    }
};
