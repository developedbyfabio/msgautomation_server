<?php

namespace App\Livewire;

use App\Models\Channel;
use App\Whatsapp\EvolutionApi;
use Livewire\Component;

/**
 * Indicador de status VIVO da conexao da Evolution (R7). Faz poll leve do estado real
 * e sincroniza channels.status. Oferece reconectar / ver QR quando a sessao cai.
 *
 * Nao toca no motor de auto-resposta. So leitura de estado + QR (admin da instancia).
 */
class StatusConexao extends Component
{
    public string $state = 'verificando';
    public bool $showQr = false;
    public ?string $qr = null;
    public ?string $qrError = null;

    public bool $confirmingDisconnect = false;

    public function refresh(EvolutionApi $api): void
    {
        try {
            $resp = $api->connectionState();
            $this->state = $resp->successful()
                ? (string) (data_get($resp->json(), 'instance.state') ?? data_get($resp->json(), 'state') ?? 'desconhecido')
                : 'desconhecido';
        } catch (\Throwable) {
            $this->state = 'desconhecido';
        }

        $this->syncChannel();
    }

    /**
     * Sincroniza channels.status SO com estados DEFINITIVOS da Evolution. Em
     * 'desconhecido'/'verificando' (Evolution momentaneamente inacessivel) NAO
     * rebaixa pra disconnected — senao um blip de rede chutaria o Fabio pra tela
     * de QR no meio do uso. O gate de conexao confia nesse status.
     */
    private function syncChannel(): void
    {
        $map = ['open' => 'connected', 'connecting' => 'connecting', 'close' => 'disconnected'];
        if (! isset($map[$this->state])) {
            return;
        }

        Channel::query()
            ->where('instance', config('services.evolution.instance'))
            ->update(['status' => $map[$this->state]]);
    }

    public function confirmDisconnect(): void
    {
        $this->confirmingDisconnect = true;
    }

    public function cancelDisconnect(): void
    {
        $this->confirmingDisconnect = false;
    }

    /** Desconecta de verdade (logout na Evolution). So por acao explicita + confirmacao. */
    public function disconnectConfirmed(EvolutionApi $api)
    {
        $this->confirmingDisconnect = false;

        try {
            $resp = $api->logout();
            if (! $resp->successful()) {
                $this->dispatch('toast', message: 'Falha ao desconectar (HTTP ' . $resp->status() . ').', type: 'error');

                return null;
            }
        } catch (\Throwable) {
            $this->dispatch('toast', message: 'Evolution inacessivel.', type: 'error');

            return null;
        }

        $this->state = 'close';
        $this->syncChannel();
        $this->dispatch('toast', message: 'WhatsApp desconectado. Escaneie o QR para reconectar.');

        return $this->redirectRoute('conexao', navigate: true);
    }

    public function abrirQr(EvolutionApi $api): void
    {
        $this->showQr = true;
        $this->qr = null;
        $this->qrError = null;

        try {
            $resp = $api->connect();
            if (! $resp->successful()) {
                $this->qrError = 'Falha ao obter o QR (HTTP ' . $resp->status() . ').';

                return;
            }

            $data = $resp->json();
            if ((data_get($data, 'instance.state') === 'open') || (data_get($data, 'state') === 'open')) {
                $this->state = 'open';
                $this->showQr = false;
                $this->syncChannel();

                return;
            }

            $b64 = data_get($data, 'base64');
            if (is_string($b64) && $b64 !== '') {
                $this->qr = str_starts_with($b64, 'data:') ? $b64 : 'data:image/png;base64,' . $b64;
            } else {
                $this->qrError = 'Sem QR no retorno da Evolution.';
            }
        } catch (\Throwable) {
            $this->qrError = 'Evolution inacessivel.';
        }
    }

    public function fecharQr(): void
    {
        $this->showQr = false;
        $this->qr = null;
        $this->qrError = null;
    }

    public function render()
    {
        return view('livewire.status-conexao');
    }
}
