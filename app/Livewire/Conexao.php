<?php

namespace App\Livewire;

use App\Channels\Evolution\EvolutionProvider;
use App\Models\Channel;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * S3 — tela de conexao (QR). Quando a sessao NAO esta `open`, a UI cai aqui:
 * mostra o QR (endpoint connect da Evolution), faz polling do estado e, ao
 * conectar, segue pras conversas. Botao de gerar novo QR se expirar.
 *
 * Nao toca no motor de auto-resposta. So admin da instancia (estado + QR).
 */
#[Layout('components.layouts.app')]
class Conexao extends Component
{
    public string $state = 'verificando';
    public ?string $qr = null;
    public ?string $qrError = null;

    public function mount(EvolutionProvider $provider)
    {
        return $this->poll($provider);
    }

    /** Poll do estado; se ja conectou, segue pras conversas. */
    /** MT-2: o canal DA CONTA do contexto (a tela opera sempre nele). */
    private function canal(): ?Channel
    {
        return Channel::query()->oldest('id')->first();
    }

    public function poll(EvolutionProvider $provider)
    {
        try {
            $resp = $provider->api($this->canal())->connectionState();
            $this->state = $resp->successful()
                ? (string) (data_get($resp->json(), 'instance.state') ?? data_get($resp->json(), 'state') ?? 'desconhecido')
                : 'desconhecido';
        } catch (\Throwable) {
            $this->state = 'desconhecido';
        }

        $this->syncChannel();

        if ($this->state === 'open') {
            return $this->redirectRoute('conversas', navigate: true);
        }

        // Sem QR ainda e fora do open -> tenta obter um.
        if ($this->qr === null && $this->qrError === null) {
            $this->gerarQr($provider);
        }

        return null;
    }

    /** (Re)gera o QR via endpoint connect da Evolution. */
    public function gerarQr(EvolutionProvider $provider)
    {
        $this->qr = null;
        $this->qrError = null;

        try {
            $resp = $provider->api($this->canal())->connect();
            if (! $resp->successful()) {
                $this->qrError = 'Falha ao obter o QR (HTTP ' . $resp->status() . ').';

                return null;
            }

            $data = $resp->json();

            // Ja conectou nesse meio tempo.
            if ((data_get($data, 'instance.state') === 'open') || (data_get($data, 'state') === 'open')) {
                $this->state = 'open';
                $this->syncChannel();

                return $this->redirectRoute('conversas', navigate: true);
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

        return null;
    }

    private function syncChannel(): void
    {
        $map = ['open' => 'connected', 'connecting' => 'connecting', 'close' => 'disconnected'];
        if (! isset($map[$this->state])) {
            return;
        }

        // MT-2: sincroniza o canal DA CONTA (nunca o de outra).
        $this->canal()?->update(['status' => $map[$this->state]]);
    }

    public function render()
    {
        return view('livewire.conexao');
    }
}
