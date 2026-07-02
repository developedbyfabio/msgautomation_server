<?php

namespace App\Console\Commands;

use App\Channels\Evolution\EvolutionProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Obtem o QR da instancia (base64), salva como PNG e mostra o caminho + pairing code.
 * Usado no GATE: o Fabio escaneia com o celular do numero pessoal.
 */
class EvolutionQr extends Command
{
    protected $signature = 'evolution:qr {--account= : ID da conta (default: a mais antiga)}';

    protected $description = 'Gera/exibe o QR da instancia da Evolution para conectar o numero';

    public function handle(EvolutionProvider $provider): int
    {
        $api = $provider->api($this->canalDaConta()); // MT-2: canal DA CONTA
        $resp = $api->connect();

        if (! $resp->successful()) {
            $this->error("Falha ao obter QR (HTTP {$resp->status()}): " . $resp->body());

            return self::FAILURE;
        }

        $data = $resp->json();
        $base64 = data_get($data, 'base64');
        $pairing = data_get($data, 'pairingCode') ?? data_get($data, 'code');

        if (data_get($data, 'instance.state') === 'open' || data_get($data, 'state') === 'open') {
            $this->info('A instancia ja esta CONECTADA (state=open). Nao precisa de QR.');

            return self::SUCCESS;
        }

        if (! is_string($base64) || $base64 === '') {
            $this->warn('Sem QR no retorno. Conteudo: ' . json_encode($data));

            return self::FAILURE;
        }

        $png = base64_decode(preg_replace('#^data:image/\w+;base64,#', '', $base64), true);
        if ($png === false) {
            $this->error('QR base64 invalido.');

            return self::FAILURE;
        }

        $path = 'qr/' . $api->instance() . '.png';
        Storage::disk('local')->put($path, $png);
        $full = Storage::disk('local')->path($path);

        $this->info('QR salvo em: ' . $full);
        if (is_string($pairing) && $pairing !== '') {
            $this->line('Pairing code (alternativa ao QR): ' . $pairing);
        }
        $this->line('Escaneie pelo WhatsApp do celular: Aparelhos conectados -> Conectar aparelho.');

        return self::SUCCESS;
    }

    /** MT-2: resolve o canal da conta (--account ou a mais antiga). */
    private function canalDaConta(): ?\App\Models\Channel
    {
        $account = $this->option('account')
            ? \App\Models\Account::find((int) $this->option('account'))
            : \App\Models\Account::query()->oldest('id')->first();

        return $account ? \App\Models\Channel::defaultFor($account->id) : null;
    }
}
