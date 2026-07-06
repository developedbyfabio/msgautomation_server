<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
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
