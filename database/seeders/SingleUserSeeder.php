<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * C1 — garante a EXISTENCIA do usuario unico da UI. A senha NAO vem mais de texto
 * no .env: a verdade e o hash no banco, definido pelo Fabio via `php artisan
 * msg:auth:senha`.
 *
 * - Se o usuario ja existe: nao mexe na senha (preserva a que o Fabio definiu).
 * - Se nao existe: cria com um hash ALEATORIO inutilizavel (ninguem conhece) ate o
 *   Fabio rodar o comando e definir a propria. Sem senha em texto em lugar nenhum.
 */
class SingleUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('auth.single_user.email');
        $name = (string) config('auth.single_user.name', 'Operador');

        if (User::query()->exists()) {
            return; // ja existe -> nada a fazer (nao toca na senha).
        }

        User::create([
            'name' => $name,
            'email' => $email,
            // Hash aleatorio: conta fica "trancada" ate `msg:auth:senha`.
            'password' => Hash::make(Str::random(48)),
        ]);

        $this->command?->warn("Usuario {$email} criado sem senha utilizavel. Rode: php artisan msg:auth:senha");
    }
}
