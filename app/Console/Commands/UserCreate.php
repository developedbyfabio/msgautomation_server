<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * MT-1 — cria/vincula um usuario a uma conta (fase 1: papel OWNER; gestao por
 * tela fica pro futuro). Senha SEMPRE via prompt oculto — nunca em argumento,
 * historico de shell ou log.
 */
class UserCreate extends Command
{
    protected $signature = 'msg:user:create {email} {--account= : ID da conta (default: a mais antiga)} {--name= : Nome (default: parte local do email)}';

    protected $description = 'Cria um usuario (senha via prompt oculto) e o vincula como owner de uma conta';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Email invalido.');

            return self::FAILURE;
        }

        $account = $this->option('account')
            ? Account::find((int) $this->option('account'))
            : Account::query()->oldest('id')->first();
        if (! $account) {
            $this->error('Conta nao encontrada. Informe --account= valido.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $senha = (string) $this->secret('Senha (minimo 10 caracteres)');
            if (mb_strlen($senha) < 10) {
                $this->error('Senha muito curta (minimo 10).');

                return self::FAILURE;
            }
            if ($senha !== (string) $this->secret('Confirme a senha')) {
                $this->error('As senhas nao conferem.');

                return self::FAILURE;
            }

            $user = User::create([
                'name' => (string) ($this->option('name') ?: ucfirst(explode('@', $email)[0])),
                'email' => $email,
                'password' => Hash::make($senha),
            ]);
            $this->info("Usuario criado: {$user->email} (id {$user->id}).");
        } else {
            $this->line("Usuario ja existe (id {$user->id}) — garantindo o vinculo.");
        }

        // Vinculo idempotente (unique account+user); papel owner na fase 1 (D3).
        $user->accounts()->syncWithoutDetaching([$account->id => ['role' => 'owner']]);
        $this->info("Vinculo OK: {$user->email} e owner da conta '{$account->name}' (id {$account->id}).");

        return self::SUCCESS;
    }
}
