<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Fatia 25 — verificado POR CONSTRUCAO: criar usuario e ato PRIVILEGIADO em
     * todos os caminhos do sistema (console user:create, admin/tenants,
     * CreateTenant) — quem cria responde pelo e-mail, entao o usuario nasce
     * verificado. A UNICA origem nao-confiavel e o cadastro publico (Fatia 25),
     * que marca explicitamente email_verified_at = null e exige a confirmacao
     * pelo link assinado. Desligar o default exige setar o atributo de verdade:
     * factory unverified() (factories nao passam pelo guard) ou forceFill
     * (RegisterTenant) — passar a chave no create() NAO basta (nao e fillable,
     * o guard descarta). Friccao proposital: nao-verificado e decisao explicita.
     */
    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (! array_key_exists('email_verified_at', $user->getAttributes())) {
                $user->email_verified_at = now();
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'terms_accepted_at' => 'datetime', // Fatia 25: consentimento LGPD (so cadastro publico)
            'password' => 'hashed',
            // Prompt 22 — super-admin da plataforma. Fora do #[Fillable] de proposito
            // (nao mass-assignable): so vira true via seed/tinker, nunca por form.
            'is_platform_admin' => 'boolean',
        ];
    }

    /** MT-1 — contas do usuario (pivot account_user; role owner|operador, D3). */
    public function accounts(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(\App\Models\Account::class, 'account_user')
            ->withPivot('role')->withTimestamps();
    }

    /** Fatia 22 — papel do usuario NA conta (por conta; null = sem vinculo). */
    public function roleIn(int $accountId): ?string
    {
        $pivot = $this->accounts()->whereKey($accountId)->first()?->pivot;

        return $pivot?->role !== null && $pivot->role !== '' ? (string) $pivot->role : null;
    }

    public function isOwnerOf(int $accountId): bool
    {
        return $this->roleIn($accountId) === 'owner';
    }
}
