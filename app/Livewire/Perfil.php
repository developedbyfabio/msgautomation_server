<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Prompt 01 — /perfil: dados do PROPRIO usuario logado (MT-1: nunca de outro;
 * toda escrita e em auth()->user(), sem id vindo da tela). Troca de email e
 * senha SEMPRE com a senha atual; gestao do 2FA (TOTP via Fortify) com senha
 * nas acoes sensiveis (ativar/desativar/regenerar recovery codes).
 */
#[Layout('components.layouts.app')]
class Perfil extends Component
{
    // Troca de email
    public string $emailNovo = '';
    public string $senhaEmail = '';

    // Troca de senha
    public string $senhaAtual = '';
    public string $senhaNova = '';
    public string $senhaNova_confirmation = '';

    // 2FA
    public string $senha2fa = '';
    public string $codigo2fa = '';
    public bool $mostrarRecovery = false;

    public function mount(): void
    {
        $this->emailNovo = (string) auth()->user()->email;
    }

    /** Senha atual confere? Erro de validacao no campo indicado se nao. */
    private function exigirSenha(string $senha, string $campo): void
    {
        if ($senha === '' || ! Hash::check($senha, (string) auth()->user()->fresh()->password)) {
            throw ValidationException::withMessages([$campo => 'Senha atual incorreta.']);
        }
    }

    // ---- email -----------------------------------------------------------------

    public function salvarEmail(): void
    {
        $this->resetErrorBag();
        $this->exigirSenha($this->senhaEmail, 'senhaEmail');

        $this->validate([
            'emailNovo' => 'required|email|unique:users,email,' . auth()->id(),
        ], [], ['emailNovo' => 'e-mail']);

        auth()->user()->fresh()->update(['email' => $this->emailNovo]);
        $this->senhaEmail = '';
        $this->dispatch('toast', message: 'E-mail atualizado.');
    }

    // ---- senha -----------------------------------------------------------------

    public function salvarSenha(): void
    {
        $this->resetErrorBag();
        $this->exigirSenha($this->senhaAtual, 'senhaAtual');

        $this->validate([
            'senhaNova' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [], ['senhaNova' => 'nova senha']);

        auth()->user()->fresh()->update(['password' => $this->senhaNova]); // cast 'hashed'
        $this->reset('senhaAtual', 'senhaNova', 'senhaNova_confirmation');
        $this->dispatch('toast', message: 'Senha atualizada.');
    }

    // ---- 2FA -------------------------------------------------------------------

    public function ativar2fa(EnableTwoFactorAuthentication $enable): void
    {
        $this->resetErrorBag();
        $this->exigirSenha($this->senha2fa, 'senha2fa');

        $enable(auth()->user()->fresh());
        $this->senha2fa = '';
        $this->mostrarRecovery = false;
        $this->dispatch('toast', message: 'Escaneie o QR e confirme com um codigo pra LIGAR o 2FA.');
    }

    public function confirmar2fa(ConfirmTwoFactorAuthentication $confirm): void
    {
        $this->resetErrorBag();
        try {
            $confirm(auth()->user()->fresh(), trim($this->codigo2fa));
        } catch (ValidationException) {
            throw ValidationException::withMessages(['codigo2fa' => 'Codigo invalido — tente o proximo do app.']);
        }

        $this->codigo2fa = '';
        $this->mostrarRecovery = true; // primeira (e unica) exibicao pos-confirmacao
        $this->dispatch('toast', message: '2FA LIGADO. Guarde os codigos de recuperacao.');
    }

    public function regenerarCodigos(GenerateNewRecoveryCodes $generate): void
    {
        $this->resetErrorBag();
        $this->exigirSenha($this->senha2fa, 'senha2fa');

        $generate(auth()->user()->fresh());
        $this->senha2fa = '';
        $this->mostrarRecovery = true;
        $this->dispatch('toast', message: 'Codigos de recuperacao NOVOS gerados (os antigos morreram).');
    }

    public function desativar2fa(DisableTwoFactorAuthentication $disable): void
    {
        $this->resetErrorBag();
        $this->exigirSenha($this->senha2fa, 'senha2fa');

        $disable(auth()->user()->fresh());
        $this->senha2fa = '';
        $this->mostrarRecovery = false;
        $this->dispatch('toast', message: '2FA desligado.');
    }

    public function render()
    {
        $user = auth()->user()->fresh();

        return view('livewire.perfil', [
            'user' => $user,
            // pendente = secret gerado mas ainda nao confirmado com codigo
            'twofaPendente' => $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null,
            'twofaAtivo' => $user->hasEnabledTwoFactorAuthentication(),
            'recoveryCodes' => $this->mostrarRecovery && $user->two_factor_recovery_codes
                ? $user->recoveryCodes()
                : [],
        ]);
    }
}
