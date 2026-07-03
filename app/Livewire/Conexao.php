<?php

namespace App\Livewire;

use App\Channels\Evolution\ChannelProvisioner;
use App\Channels\Evolution\EvolutionProvider;
use App\Models\Account;
use App\Models\Channel;
use App\Tenancy\AccountContext;
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

    // Prompt 23 — self-service: conta SEM canal mostra "Conectar WhatsApp" (dispara o
    // provisionamento); com canal, e o fluxo de QR/estado de sempre.
    public bool $temCanal = false;
    public ?string $provisionError = null;

    public function mount(EvolutionProvider $provider)
    {
        $this->temCanal = $this->canal() !== null;

        // Sem canal: nao faz poll/QR (evitaria cair no fallback do .env, canal alheio).
        // A tela mostra o botao Conectar; o poll comeca so depois de provisionar.
        if (! $this->temCanal) {
            $this->state = 'desconhecido';

            return null;
        }

        return $this->poll($provider);
    }

    private function accountId(): int
    {
        return app(AccountContext::class)->id();
    }

    /** MT-2: o canal DA CONTA ativa (a tela opera sempre nele; nunca no de outra conta). */
    private function canal(): ?Channel
    {
        return Channel::defaultFor($this->accountId());
    }

    /**
     * Prompt 23 — conecta o WhatsApp da CONTA ATIVA: provisiona (instancia
     * `conta-{id}-{slug}` + token + webhook, via ChannelProvisioner, idempotente)
     * e ja busca o QR. Guard de idempotencia: no-op se a conta ja tem canal.
     */
    public function conectar(ChannelProvisioner $provisioner, EvolutionProvider $provider)
    {
        $this->provisionError = null;

        // Idempotencia: com canal, nao recria/duplica (so segue pro QR).
        if ($this->canal() !== null) {
            $this->temCanal = true;

            return $this->poll($provider);
        }

        $account = Account::find($this->accountId()); // SEMPRE a conta ativa
        if ($account === null) {
            $this->provisionError = 'Conta nao encontrada.';

            return null;
        }

        try {
            $provisioner->provision($account); // cria canal/instancia/webhook DESTA conta
        } catch (\Throwable $e) {
            $this->provisionError = 'Nao foi possivel criar a instancia agora. Tente de novo em instantes.';
            $this->dispatch('toast', message: $this->provisionError, type: 'error');

            return null;
        }

        $this->temCanal = true;
        $this->dispatch('toast', message: 'Instancia criada. Escaneie o QR para conectar.');

        return $this->poll($provider);
    }

    public function poll(EvolutionProvider $provider)
    {
        // Sem canal: nada a consultar (nao cair no fallback do .env). A tela mostra
        // o botao Conectar; o poll passa a valer depois de provisionar.
        if (! $this->temCanal) {
            return null;
        }

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
