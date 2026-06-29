<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * S2 — usuario unico da UI. Credenciais vem do .env (config/auth.single_user).
 * Idempotente: cria ou atualiza pelo email. So define a senha se AUTH_PASSWORD
 * estiver setado (evita gravar hash de senha vazia). NUNCA loga a senha.
 */
class SingleUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('auth.single_user.email');
        $name = (string) config('auth.single_user.name');
        $password = config('auth.single_user.password');

        if (! is_string($password) || $password === '') {
            $this->command?->warn('AUTH_PASSWORD nao definido no .env — usuario NAO criado/atualizado.');

            return;
        }

        User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make($password)],
        );

        $this->command?->info("Usuario unico garantido: {$email} (senha vem do .env).");
    }
}
