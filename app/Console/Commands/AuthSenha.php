<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * C1 — o Fabio define a PROPRIA senha do login (single-user).
 *
 * Input OCULTO (secret) e digitado pelo Fabio; nunca ecoa na tela nem vai pra log.
 * A verdade passa a ser o HASH no banco (nao ha senha em texto no .env). Confirma
 * 2x, exige minimo 8 caracteres. Cria o usuario se faltar. Opcionalmente troca o email.
 */
class AuthSenha extends Command
{
    protected $signature = 'msg:auth:senha {--email= : Define/troca o email do usuario unico}';

    protected $description = 'Define (interativamente) a senha do usuario unico da UI — hash no banco';

    public function handle(): int
    {
        $emailAtual = (string) (User::query()->oldest('id')->value('email')
            ?? config('auth.single_user.email'));

        $email = (string) ($this->option('email') ?: $emailAtual);

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Email invalido.');

            return self::FAILURE;
        }

        $senha = $this->secret('Nova senha (nao aparece na tela)');
        if (! is_string($senha) || mb_strlen($senha) < 8) {
            $this->error('Senha precisa ter no minimo 8 caracteres.');

            return self::FAILURE;
        }

        $confirma = $this->secret('Confirme a senha');
        if ($senha !== $confirma) {
            $this->error('As senhas nao conferem.');

            return self::FAILURE;
        }

        $user = User::query()->oldest('id')->first();

        if ($user) {
            $user->forceFill([
                'email' => $email,
                'password' => Hash::make($senha),
            ])->save();
        } else {
            User::create([
                'name' => (string) config('auth.single_user.name', 'Operador'),
                'email' => $email,
                'password' => Hash::make($senha),
            ]);
        }

        $this->info("Senha definida para {$email}. Use-a no /login.");

        return self::SUCCESS;
    }
}
