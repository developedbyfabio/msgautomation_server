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

        $key = 'login:' . Str::lower($this->email) . '|' . request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'email' => "Muitas tentativas. Tente de novo em {$seconds}s.",
            ]);
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages([
                'email' => 'Credenciais invalidas.',
            ]);
        }

        RateLimiter::clear($key);
        session()->regenerate();

        return $this->redirectRoute('conversas', navigate: false);
    }

    public function render()
    {
        return view('livewire.login');
    }
}
