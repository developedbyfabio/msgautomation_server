<?php

namespace App\Livewire;

use App\Enums\OperationMode;
use App\Models\AutoReplySetting;
use App\Tenancy\AccountContext;
use Livewire\Component;

/**
 * Fatia 2 — toggle do Modo de Operacao (Pessoal vs Automatico) no header.
 * Persiste operation_mode SERVER-SIDE na auto_reply_settings DA CONTA ATIVA
 * (AccountContext — resolvido pelo SetAccountContext em todo request web,
 * inclusive nos updates do Livewire; mesmo padrao do Configuracoes::settings()).
 *
 * INERTE no robo: o pipeline so le a flag na Fatia 4 — aqui so grava/le estado.
 * Isolamento: escrita SEMPRE na linha da conta ativa (firstOrCreate por
 * account_id explicito + BelongsToAccount) — nunca contexto global/defaultFor.
 */
class OperationModeToggle extends Component
{
    public bool $auto = false;

    public function mount(): void
    {
        $this->auto = $this->settings()->operation_mode === OperationMode::Auto;
    }

    public function toggle(): void
    {
        // Re-resolve a CONTA ATIVA neste request (nunca cachear entre requests).
        $settings = $this->settings();

        $novo = $settings->operation_mode === OperationMode::Auto
            ? OperationMode::Personal
            : OperationMode::Auto;

        $settings->update(['operation_mode' => $novo]);

        // Re-le do banco apos salvar (estado espelha a persistencia, nao a intencao).
        $this->auto = $this->settings()->fresh()->operation_mode === OperationMode::Auto;

        $this->dispatch('toast', message: 'Modo de operacao: ' . $novo->label() . '.');
    }

    public function label(): string
    {
        return ($this->auto ? OperationMode::Auto : OperationMode::Personal)->label();
    }

    /** MESMO resolver do Configuracoes::settings(): conta ativa do AccountContext. */
    private function settings(): AutoReplySetting
    {
        return AutoReplySetting::firstOrCreate(['account_id' => app(AccountContext::class)->id()]);
    }

    public function render()
    {
        return view('livewire.operation-mode-toggle');
    }
}
