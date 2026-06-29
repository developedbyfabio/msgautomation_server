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

    private function syncChannel(): void
    {
        $map = ['open' => 'connected', 'connecting' => 'connecting', 'close' => 'disconnected'];
        $status = $map[$this->state] ?? 'disconnected';

        Channel::query()
            ->where('instance', config('services.evolution.instance'))
            ->update(['status' => $status]);
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
