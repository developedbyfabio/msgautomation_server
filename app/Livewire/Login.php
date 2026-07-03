<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * S2 — login single-user. A UI estava aberta na LAN (0.0.0.0:8080) sem auth.
 * Sessao do Laravel (guard web). Throttle simples anti-forca-bruta.
 */
#[Layout('components.layouts.auth')]
class Login extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function login()
    {
        $this->validate();

        $ip = request()->ip();
        // Dois freios anti-forca-bruta (Prompt 28):
        //  - por (email+IP): 5/min — protege UMA conta;
        //  - por IP: 20/min — corta password-spraying (muitos emails, mesmo IP).
        $key = 'login:' . Str::lower($this->email) . '|' . $ip;
        $ipKey = 'login-ip:' . $ip;

        if (RateLimiter::tooManyAttempts($key, 5) || RateLimiter::tooManyAttempts($ipKey, 20)) {
            $seconds = max(RateLimiter::availableIn($key), RateLimiter::availableIn($ipKey));
            throw ValidationException::withMessages([
                'email' => "Muitas tentativas. Tente de novo em {$seconds}s.",
            ]);
        }

        // Prompt 01 — 2FA: com credenciais VALIDAS e 2FA confirmado, NAO loga
        // ainda: guarda o desafio na sessao (as MESMAS chaves que o pipeline do
        // Fortify usa) e cai no /two-factor-challenge. O POST do desafio e do
        // Fortify (valida codigo/recovery com throttle proprio e so entao loga).
        $user = \App\Models\User::query()->where('email', $this->email)->first();
        if ($user
            && \Illuminate\Support\Facades\Hash::check($this->password, $user->password)
            && $user->hasEnabledTwoFactorAuthentication()) {
            RateLimiter::clear($key);
            session()->put([
                'login.id' => $user->getKey(),
                'login.remember' => $this->remember,
            ]);

            return $this->redirectRoute('two-factor.login', navigate: false);
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($key, 60);
            RateLimiter::hit($ipKey, 60);
            // Mensagem GENERICA (anti-enumeracao): identica pra email inexistente e
            // senha errada — nao revela se o email existe.
            throw ValidationException::withMessages([
                'email' => 'Credenciais invalidas.',
            ]);
        }

        RateLimiter::clear($key); // limpa o freio da conta; o freio por IP decai sozinho
        session()->regenerate();

        return $this->redirectRoute('conversas', navigate: false);
    }

    public function render()
    {
        return view('livewire.login');
    }
}
