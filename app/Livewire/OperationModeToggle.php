<?php

namespace App\Livewire;

use App\Enums\OperationMode;
use App\Models\AutoReplySetting;
use App\Models\Flow;
use App\Tenancy\AccountContext;
use Livewire\Component;

/**
 * Fatia 2 — toggle do Modo de Operacao (Pessoal vs Automatico) no header.
 * Persiste operation_mode SERVER-SIDE na auto_reply_settings DA CONTA ATIVA
 * (AccountContext — resolvido pelo SetAccountContext em todo request web,
 * inclusive nos updates do Livewire; mesmo padrao do Configuracoes::settings()).
 *
 * Fatia 4b — ligar (Personal -> Auto) exige CONFIRMACAO (o modo muda o
 * comportamento de verdade, Fatia 4); desligar e IMEDIATO (direcao segura).
 *
 * Fatia 9 — o modal de ativacao passou a ESCOLHER o fluxo: select dos fluxos
 * HABILITADOS da conta (obrigatorio quando existir algum) e Confirmar grava
 * default_flow_id + operation_mode=auto NA MESMA acao. A validacao de posse e
 * server-side (mesma disciplina da Fatia 3: id de outra conta ou de fluxo
 * desabilitado e rejeitado sem vazar existencia — a UI desabilitada nao basta,
 * a acao Livewire e forjavel). Sem nenhum fluxo habilitado, resta a UNICA
 * variante de aviso: ativar mesmo assim e permitido (degradacao graciosa da
 * Fatia 4 — auto sem fluxo valido = silencio, nunca erro).
 *
 * Isolamento: escrita SEMPRE na linha da conta ativa (firstOrCreate por
 * account_id explicito + BelongsToAccount) — nunca contexto global/defaultFor.
 */
class OperationModeToggle extends Component
{
    public bool $auto = false;

    // Fatia 4b/9 — confirmacao ao LIGAR. fluxoEscolhido e o select do modal;
    // pre-selecionado com o default atual quando ele e valido/habilitado.
    public bool $confirming = false;

    public ?int $fluxoEscolhido = null;

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

        // Ligar (Personal -> Auto): NAO persiste ainda — abre a confirmacao.
        // Pre-selecao: o default atual, SE aponta fluxo valido/habilitado.
        $flow = $settings->defaultFlow;
        $this->fluxoEscolhido = ($flow !== null && (bool) $flow->enabled) ? (int) $flow->id : null;
        $this->resetErrorBag();
        $this->confirming = true;
    }

    /**
     * Confirmou: grava default_flow_id + operation_mode=auto JUNTOS na conta
     * ativa. O estado dos fluxos e re-lido AGORA (nao no clique que abriu o
     * modal) — a validacao vale contra o banco do momento da confirmacao.
     */
    public function confirmarAtivacao(): void
    {
        $settings = $this->settings();
        $habilitados = $this->fluxosHabilitados();

        if ($habilitados->isEmpty()) {
            // Sem nenhum fluxo habilitado: ativa mesmo assim (o aviso do modal ja
            // explicou o silencio); default_flow_id fica como esta (null/antigo).
            $settings->update(['operation_mode' => OperationMode::Auto]);
        } else {
            // Escolha OBRIGATORIA e validada server-side: so fluxo DA CONTA ATIVA
            // e HABILITADO (a lista ja nasce filtrada assim) — mesma mensagem pra
            // id alheio, desabilitado ou inexistente (nao vaza existencia).
            if (! $this->fluxoEscolhido || ! $habilitados->contains('id', (int) $this->fluxoEscolhido)) {
                $this->addError('fluxoEscolhido', 'Escolha um fluxo habilitado da sua conta.');

                return; // nada persiste; modal segue aberto
            }
            $settings->update([
                'default_flow_id' => (int) $this->fluxoEscolhido,
                'operation_mode' => OperationMode::Auto,
            ]);
        }

        $this->auto = $this->settings()->fresh()->operation_mode === OperationMode::Auto;
        $this->confirming = false;

        $this->dispatch('toast', message: 'Modo de operacao: ' . OperationMode::Auto->label() . '.');
    }

    /** Cancelou: nada persistido (nem modo, nem fluxo) — permanece Personal. */
    public function cancelarAtivacao(): void
    {
        $this->confirming = false;
        $this->fluxoEscolhido = null;
        $this->resetErrorBag();
    }

    public function label(): string
    {
        return ($this->auto ? OperationMode::Auto : OperationMode::Personal)->label();
    }

    public function mount(): void
    {
        $this->auto = $this->settings()->operation_mode === OperationMode::Auto;
    }

    /** MESMO resolver do Configuracoes::settings(): conta ativa do AccountContext. */
    private function settings(): AutoReplySetting
    {
        return AutoReplySetting::firstOrCreate(['account_id' => app(AccountContext::class)->id()]);
    }

    /**
     * Fluxos HABILITADOS da conta ativa — where account_id EXPLICITO alem do
     * escopo BelongsToAccount (defesa em profundidade, como nas rules da Fatia 3).
     */
    private function fluxosHabilitados(): \Illuminate\Support\Collection
    {
        return Flow::query()
            ->where('account_id', app(AccountContext::class)->id())
            ->where('enabled', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function render()
    {
        return view('livewire.operation-mode-toggle', [
            // Lista fresca SO com o modal aberto (header renderiza em toda pagina).
            'fluxosHabilitados' => $this->confirming ? $this->fluxosHabilitados() : collect(),
        ]);
    }
}
