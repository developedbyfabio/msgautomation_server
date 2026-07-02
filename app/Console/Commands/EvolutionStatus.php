<?php

namespace App\Console\Commands;

use App\Models\IncomingMessage;
use App\Channels\Evolution\EvolutionProvider;
use Illuminate\Console\Command;

/**
 * Mostra o estado da conexao da instancia e um resumo das ultimas mensagens recebidas.
 * Util pra acompanhar o gate e diagnosticar quedas de sessao.
 */
class EvolutionStatus extends Command
{
    protected $signature = 'evolution:status {--n=5 : Quantas mensagens recentes mostrar}';

    protected $description = 'Estado da conexao da instancia + ultimas mensagens recebidas';

    public function handle(EvolutionProvider $provider): int
    {
        $api = $provider->api(); // CH-1: comando opera VIA provider
        $resp = $api->connectionState();
        if ($resp->successful()) {
            $state = data_get($resp->json(), 'instance.state') ?? data_get($resp->json(), 'state') ?? 'desconhecido';
            $this->info("Instancia {$api->instance()} -> estado: {$state}");
        } else {
            $this->warn("Nao foi possivel obter o estado (HTTP {$resp->status()}).");
        }

        $n = max(1, (int) $this->option('n'));
        $msgs = IncomingMessage::query()->latest('received_at')->limit($n)->get();

        $this->line("Total de mensagens registradas: " . IncomingMessage::count());

        if ($msgs->isEmpty()) {
            $this->line('Nenhuma mensagem registrada ainda.');

            return self::SUCCESS;
        }

        $this->table(
            ['recebida_em', 'remetente', 'nome', 'tipo', 'texto'],
            $msgs->map(fn (IncomingMessage $m) => [
                optional($m->received_at)->format('d/m/Y H:i:s'),
                $m->remote_jid,
                $m->push_name,
                $m->type,
                mb_strimwidth((string) $m->text, 0, 40, '...'),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
