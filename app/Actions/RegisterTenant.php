<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Fatia 25 — provisionamento do CADASTRO PUBLICO. Reusa o CreateTenant (ponto
 * unico de criacao de tenant: Account + owner + board/variaveis via booted())
 * e acrescenta, NA MESMA transacao (a interna vira savepoint): perfil PF/PJ +
 * endereco, estado de TRIAL (subscription_status='trial', trial_ends_at=+7d),
 * consentimento LGPD e a marca de NAO-verificado (self-signup e a unica origem
 * nao-vouched — o painel fica atras do gate 'verified' ate confirmar o e-mail).
 *
 * TUDO OU NADA: qualquer falha em qualquer passo -> rollback total. Nunca
 * conta sem owner, nunca user orfao, nunca perfil pela metade.
 */
class RegisterTenant
{
    public function __construct(private CreateTenant $createTenant)
    {
    }

    /**
     * @param array{
     *   account_name: string, owner_name: string, email: string, password: string,
     *   person_type: string, document: string, razao_social: ?string, phone: string,
     *   cep: string, endereco: string, numero: string, complemento: ?string,
     *   bairro: string, cidade: string, uf: string,
     * } $dados  document/cep/phone ja normalizados (so digitos), validados ANTES.
     * @return array{account: Account, owner: User}
     */
    public function handle(array $dados): array
    {
        return DB::transaction(function () use ($dados) {
            ['account' => $account, 'owner' => $owner] = $this->createTenant->handle(
                $dados['account_name'], $dados['owner_name'], $dados['email'], $dados['password'],
            );

            // Perfil PF/PJ + trial: forceFill (nada disso e mass-assignable de form).
            $account->forceFill([
                'person_type' => $dados['person_type'],
                'document' => $dados['document'],
                'razao_social' => $dados['razao_social'] ?: null,
                'phone' => $dados['phone'],
                'cep' => $dados['cep'],
                'endereco' => $dados['endereco'],
                'numero' => $dados['numero'],
                'complemento' => $dados['complemento'] ?: null,
                'bairro' => $dados['bairro'],
                'cidade' => $dados['cidade'],
                'uf' => $dados['uf'],
                'subscription_status' => 'trial',
                'trial_ends_at' => now()->addDays((int) config('billing.trial_days', 7)),
            ])->save();

            $owner->forceFill([
                'email_verified_at' => null, // self-signup: confirma pelo link assinado
                'terms_accepted_at' => now(),
                'terms_version' => (string) config('billing.terms_version'),
            ])->save();

            return ['account' => $account, 'owner' => $owner];
        });
    }
}
