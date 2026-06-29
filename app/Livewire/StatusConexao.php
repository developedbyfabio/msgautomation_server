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
    /** Polls 'desconhecido' seguidos ate considerar a conexao caida (tolera blip). */
    private const MAX_BLIPS = 2;

    public string $state = 'verificando';
    public bool $showQr = false;
    public ?string $qr = null;
    public ?string $qrError = null;

    public bool $confirmingDisconnect = false;
    public int $blips = 0;

    public function refresh(EvolutionApi $api): void
    {
        $estado = 'desconhecido';
        try {
            $resp = $api->connectionState();
            if ($resp->successful()) {
                $estado = (string) (data_get($resp->json(), 'instance.state') ?? data_get($resp->json(), 'state') ?? 'desconhecido');
            }
        } catch (\Throwable) {
            $estado = 'desconhecido';
        }

        // Estado DEFINITIVO (open/connecting/close): aceita na hora, zera os blips.
        if ($estado !== 'desconhecido') {
            $this->blips = 0;
            $this->state = $estado;
            $this->syncChannel();

            return;
        }

        // 'desconhecido' = Evolution inacessivel. Tolera blip curto (mantem o estado
        // atual), mas se PERSISTIR (>= MAX_BLIPS), e honesto: marca desconectado em vez
        // de esconder atras de "conectado" otimista.
        $this->blips++;
        if ($this->blips >= self::MAX_BLIPS) {
            $this->state = 'close';
            $this->syncChannel();
        }
    }

    /**
     * Sincroniza channels.status com o estado avaliado. Estados transitorios
     * (verificando/desconhecido sob blip) nao chegam aqui — o gate de conexao
     * confia nesse status, entao so gravamos quando ha um veredito.
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
