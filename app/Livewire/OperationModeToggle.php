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
 * Fatia 4b — ligar (Personal -> Auto) exige CONFIRMACAO (o modo agora muda o
 * comportamento de verdade, Fatia 4); desligar e IMEDIATO (direcao segura).
 * Mesmo padrao do kill switch (Configuracoes::requestKillSwitch): flag de
 * confirmacao + x-modal. A copy e dinamica: variante de AVISO quando a conta
 * nao tem fluxo padrao valido/habilitado (ligar = nao responde nada ate escolher).
 *
 * Isolamento: escrita SEMPRE na linha da conta ativa (firstOrCreate por
 * account_id explicito + BelongsToAccount) — nunca contexto global/defaultFor.
 */
class OperationModeToggle extends Component
{
    public bool $auto = false;

    // Fatia 4b — confirmacao ao LIGAR. temFluxoValido e capturado NO CLIQUE
    // (estado real da conta naquele momento) e decide a variante da copy.
    public bool $confirming = false;
    public bool $temFluxoValido = false;

    public function mount(): void
    {
        $this->auto = $this->settings()->operation_mode === OperationMode::Auto;
    }

    public function toggle(): void
    {
        // Re-resolve a CONTA ATIVA neste request (nunca cachear entre requests).
        $settings = $this->settings();

        // Desligar (Auto -> Personal): IMEDIATO, sem confirmacao (direcao segura).
        if ($settings->operation_mode === OperationMode::Auto) {
            $settings->update(['operation_mode' => OperationMode::Personal]);
            $this->auto = false;
            $this->dispatch('toast', message: 'Modo de operacao: ' . OperationMode::Personal->label() . '.');

            return;
        }

        // Ligar (Personal -> Auto): NAO persiste ainda — abre a confirmacao com a
        // variante certa (fluxo padrao valido = defaultFlow existe E enabled).
        $flow = $settings->defaultFlow;
        $this->temFluxoValido = $flow !== null && (bool) $flow->enabled;
        $this->confirming = true;
    }

    /** Confirmou: persiste Auto na conta ativa (mesmo caminho isolado da Fatia 2). */
    public function confirmarAtivacao(): void
    {
        $this->settings()->update(['operation_mode' => OperationMode::Auto]);
        $this->auto = $this->settings()->fresh()->operation_mode === OperationMode::Auto;
        $this->confirming = false;

        $this->dispatch('toast', message: 'Modo de operacao: ' . OperationMode::Auto->label() . '.');
    }

    /** Cancelou: nada persistido — permanece Personal. */
    public function cancelarAtivacao(): void
    {
        $this->confirming = false;
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
